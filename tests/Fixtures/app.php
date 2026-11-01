<?php

declare(strict_types=1);

/**
 * A skeleton-style application that bills, driven through the real HTTP
 * Kernel: its plans and features, a pricing page, pages and actions gated
 * by plan and by feature, and Stripe's webhook -- as the billing docs show
 * them. Billed by tenant (placed by host) or by signed-in user.
 */

namespace Tinvar\Tests\Billing\Fixtures;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Billing\ArraySubscriptions;
use Tinvar\Billing\Attributes\RequiresFeature;
use Tinvar\Billing\Attributes\RequiresPlan;
use Tinvar\Billing\Billables;
use Tinvar\Billing\Billing;
use Tinvar\Billing\Fakes\FakeStripe;
use Tinvar\Billing\Http\StripeSignature;
use Tinvar\Billing\Http\StripeWebhook;
use Tinvar\Billing\Interval;
use Tinvar\Billing\Plan as BillingPlan;
use Tinvar\Billing\Stripe\StripeClient;
use Tinvar\Billing\SubscriptionStore;
use Tinvar\Billing\TenantBillables;
use Tinvar\Billing\UserBillables;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Support\Fixtures\Sources;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Support/Fixtures/tenants.php';

enum Feature: string
{
    case Exports = 'exports';
    case Seats = 'seats';
    case Audit = 'audit';
}

enum Plan: string implements BillingPlan
{
    case Free = 'free';
    case Pro = 'pro';
    case Team = 'team';

    public function prices(): array
    {
        return match ($this) {
            self::Free => [],
            self::Pro => ['month' => 'pro_monthly', 'year' => 'pro_yearly'],
            self::Team => ['month' => 'team_monthly'],
        };
    }

    public function features(): array
    {
        return match ($this) {
            self::Free => [],
            self::Pro => [Feature::Exports],
            self::Team => [Feature::Exports, Feature::Seats],
        };
    }

    public function trialDays(): int
    {
        return $this === self::Pro ? 14 : 0;
    }
}

const PRICES = ['pro_monthly' => 'price_ProMonthly01', 'pro_yearly' => 'price_ProYearly001', 'team_monthly' => 'price_TeamMonthly1', 'old_plan' => 'price_OldPlan00001'];

/** acme.app.test is acme's, globex.app.test globex's. */
final class FromHost
{
    public function tenantFor(Request $request): ?string
    {
        return ['acme.app.test' => 'acme', 'globex.app.test' => 'globex'][$request->header('host') ?? ''] ?? null;
    }
}

final readonly class Member implements Authenticatable
{
    public function __construct(public string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Signs in whoever `x-user` names: the test's stand-in for a session. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-user');

        return $user === null ? $next($request) : $this->auth->actingAs(new Member($user), static fn (): Response => $next($request));
    }
}

final readonly class PlanData extends Data
{
    public function __construct(public string $plan) {}
}

#[Page('/pricing', view: 'pricing'), PublicAccess, TenantFrom(FromHost::class)]
final class PricingPage
{
    public function __construct(private readonly Billing $billing) {}

    public function __invoke(): PlanData
    {
        return new PlanData($this->billing->plan()?->value ?? 'none');
    }
}

#[Page('/reports', view: 'reports'), PublicAccess, TenantFrom(FromHost::class), RequiresPlan(Plan::Pro, Plan::Team)]
final class ReportsPage
{
    public function __invoke(): PlanData
    {
        return new PlanData('reports');
    }
}

#[Layout('/team', view: 'team/layout'), TenantFrom(FromHost::class), RequiresFeature(Feature::Seats)]
final class TeamLayout
{
    public function __invoke(): ?PlanData
    {
        return null;
    }
}

#[Page('/team/members', view: 'team/members'), PublicAccess, TenantFrom(FromHost::class)]
final class TeamMembersPage
{
    public function __invoke(): PlanData
    {
        return new PlanData('members');
    }
}

final class BillingActions
{
    public function __construct(private readonly Billing $billing) {}

    #[Action(name: 'billing.checkout'), PublicAccess, NoAuthorization(reason: 'the payer is the tenant in force'), TenantFrom(FromHost::class)]
    public function checkout(Request $request): ActionResult
    {
        $plan = Plan::from((string) ($request->body['plan'] ?? 'pro'));

        return ActionResult::location($this->billing->checkout($plan, Interval::Month, 'https://acme.app.test/billing/done', 'https://acme.app.test/pricing'));
    }

    #[Action(name: 'reports.export'), PublicAccess, NoAuthorization(reason: 'a fixture'), TenantFrom(FromHost::class), RequiresFeature(Feature::Exports)]
    public function export(): ActionResult
    {
        return ActionResult::ok(['exported' => true]);
    }
}

/** For the app billed by user: no tenant. */
#[Page('/me/reports', view: 'me/reports'), PublicAccess, RequiresPlan(Plan::Pro)]
final class MyReportsPage
{
    public function __invoke(): PlanData
    {
        return new PlanData('mine');
    }
}

#[Webhook('/webhooks/stripe', verifier: StripeSignature::class)]
final class StripeEvents extends StripeWebhook {}

/** Never asked: Stripe's webhook names its own verifier. */
final class NoSecrets implements WebhookSecrets
{
    public function secretFor(string $webhook, string $tenant): ?string
    {
        return null;
    }
}

/** Now, for billing; the test moves it. */
final class Clock
{
    public static int $now = 1_800_000_000;

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(self::$now, 'UTC');
    }
}

/** The application, put together as its Kernel would. */
final class App
{
    public readonly Kernel $kernel;

    public readonly Billing $billing;

    public readonly Orm $orm;

    public readonly AuthContext $auth;

    /** @var list<\Throwable> */
    public array $reported = [];

    public function __construct(
        public readonly FakeStripe $stripe = new FakeStripe(PRICES),
        public readonly SubscriptionStore $store = new ArraySubscriptions(),
        public readonly NonceStore $nonces = new ArrayNonceStore(),
        bool $byUser = false,
        bool $registerBilling = true,
    ) {
        $connection = PdoConnection::sqlite(':memory:', 'billing-test');
        $this->orm = $byUser ? new Orm($connection, new IdentityMap('billing-test')) : new Orm($connection, new IdentityMap('billing-test'), tenants: Tenancy::flat());
        $this->auth = new AuthContext();
        $billables = $byUser ? new UserBillables($this->auth) : new TenantBillables($this->orm);
        $this->billing = self::billing($billables, $this->store, $this->stripe);

        $container = new Container()->instance(Orm::class, $this->orm)->instance(AuthContext::class, $this->auth);
        if ($registerBilling) {
            $container->instance(Billing::class, $this->billing);
        }

        $routes = new RouteRegistry();
        $routes->registerAll($byUser ? [MyReportsPage::class] : [PricingPage::class, ReportsPage::class, TeamLayout::class, TeamMembersPage::class]);
        $actions = new ActionRegistry($container->resolver());
        if (!$byUser) {
            $actions->register(BillingActions::class);
        }
        $tenants = $byUser ? null : new Sources($container->resolver());

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: $actions,
            auth: $this->auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignIn::class],
            log: function (\Throwable $e): void {
                $this->reported[] = $e;
            },
            container: $container,
            webhooks: new WebhookEndpoint([StripeEvents::class], $this->orm, $container->resolver(), new NoSecrets(), $this->nonces, clock: static fn (): int => Clock::$now, tenants: $tenants),
            tenants: $tenants,
            redirectOrigins: StripeClient::REDIRECT_ORIGINS,
        );
    }

    public static function billing(Billables $billables, SubscriptionStore $store, FakeStripe $stripe): Billing
    {
        return new Billing(Plan::class, $billables, $store, $stripe->client(), freePlan: Plan::Free, clock: Clock::now(...));
    }

    public function visit(string $path, string $host = 'acme.app.test', ?string $user = null): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host, ...($user === null ? [] : ['x-user' => $user])]),
            static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, json_encode($loaded->page) ?: '', ['content-type' => 'text/html; charset=utf-8']),
        );
    }

    /** @param array<string, mixed> $body */
    public function act(string $name, array $body = [], string $host = 'acme.app.test'): Response
    {
        return $this->kernel->handle(new Request('POST', "/_action/{$name}", [
            'host' => $host,
            'content-type' => 'application/json',
            'accept' => 'application/json',
            VerifyCsrfToken::HEADER => 't',
        ], body: $body, cookies: [VerifyCsrfToken::COOKIE => 't']), static fn (): Response => Response::make(500), json_encode($body) ?: '{}');
    }

    /**
     * Delivers $event to the webhook, signed as Stripe signs it unless told otherwise.
     *
     * @param array<string, mixed> $event
     */
    public function deliver(array $event, ?string $secret = null, ?int $timestamp = null): Response
    {
        [$request, $body] = $this->stripe->delivery($event, secret: $secret, timestamp: $timestamp ?? Clock::$now);

        return $this->kernel->handle($request, static fn (): Response => Response::make(500), $body);
    }

    /** Has $tenant subscribe through Checkout, and Stripe tell the app: the subscription Stripe holds. */
    public function subscribe(string $tenant, Plan $plan = Plan::Pro, string $host = ''): string
    {
        $this->orm->actAs($tenant, fn (): string => $this->billing->checkout($plan, Interval::Month, 'https://x.test/ok', 'https://x.test/back'));
        $subscription = $this->stripe->completeCheckout($this->stripe->lastSession());
        $this->deliver($this->stripe->event('checkout.session.completed', $this->stripe->sessions[array_key_last($this->stripe->sessions)]));

        return (string) $subscription['id'];
    }
}

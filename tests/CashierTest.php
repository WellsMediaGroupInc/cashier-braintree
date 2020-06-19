<?php

namespace Laravel\Cashier\Tests;

use Carbon\Carbon;
use Braintree\Configuration as Braintree_Configuration;
use Illuminate\Http\Request;
use Laravel\Cashier\Billable;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Laravel\Cashier\Http\Controllers\WebhookController;

class CashierTest extends TestCase
{
    public function setUp(): void
    {
        Braintree_Configuration::environment('sandbox');
        Braintree_Configuration::merchantId('yh3skqys4vrpjyj8');
        Braintree_Configuration::publicKey('x9q54dv5q78hyh9d');
        Braintree_Configuration::privateKey('15b6b9d1daf395487157cfc30383bb58');

        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('braintree_id')->nullable();
            $table->string('paypal_email')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string('braintree_id');
            $table->string('braintree_plan');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
    }

    public function test_subscriptions_can_be_created()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPM0001')->create($this->getTestToken());

        $this->assertEquals(1, count($owner->subscriptions));
        $this->assertNotNull($owner->subscription('main')->braintree_id);

        $this->assertTrue($owner->subscribed('main'));
        $this->assertTrue($owner->subscribed('main', 'IJPM0001'));
        $this->assertFalse($owner->subscribed('main', 'IJPY0001'));
        $this->assertTrue($owner->subscription('main')->active());
        $this->assertFalse($owner->subscription('main')->cancelled());
        $this->assertFalse($owner->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $owner->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Swap Plan
        $subscription->swap('IJPY0001');

        $this->assertEquals('IJPY0001', $subscription->braintree_plan);

        // Invoice Tests
        $invoice = $owner->invoicesIncludingPending()[0];
        $foundInvoice = $owner->findInvoice($invoice->id);

        $this->assertEquals($invoice->id, $foundInvoice->id);
        $this->assertEquals('$79.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertCount(0, $invoice->coupons());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function test_creating_subscription_with_coupons()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPY0001')
            ->withCoupon('5tb2')
            ->create($this->getTestToken());

        $subscription = $owner->subscription('main');

        $this->assertTrue($owner->subscribed('main'));
        $this->assertTrue($owner->subscribed('main', 'IJPY0001'));
        $this->assertFalse($owner->subscribed('main', 'IJPM0001'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $owner->invoicesIncludingPending()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$69.00', $invoice->total());
        $this->assertEquals('$10.00', $invoice->amountOff());
    }

    public function test_creating_subscription_with_trial()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPY0001')
            ->trialDays(7)
            ->create($this->getTestToken());

        $subscription = $owner->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        // Braintree trials are just cancelled out right since we have
        // no good way to cancel them and then later resume them.
        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
    }

    public function test_applying_coupons_to_existing_customers()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPY0001')->create($this->getTestToken());

        // Apply Coupon
        $owner->applyCoupon('5tb2', 'main');

        $subscription = $owner->subscription('main')->asBraintreeSubscription();

        foreach ($subscription->discounts as $discount) {
            if ($discount->id === '5tb2') {
                return;
            }
        }

        $this->fail('Coupon was not applied to existing customer.');
    }

    public function test_yearly_to_monthly_properly_prorates()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPY0001')->create($this->getTestToken());

        $this->assertEquals(1, count($owner->subscriptions));
        $this->assertNotNull($owner->subscription('main')->braintree_id);

        // Swap To Monthly
        $owner->subscription('main')->swap('IJPM0001');
        $owner = $owner->fresh();

        $this->assertEquals(2, count($owner->subscriptions));
        $this->assertNotNull($owner->subscription('main')->braintree_id);
        $this->assertEquals('IJPM0001', $owner->subscription('main')->braintree_plan);

        $braintreeSubscription = $owner->subscription('main')->asBraintreeSubscription();

        foreach ($braintreeSubscription->discounts as $discount) {
            if ($discount->id === '5tb2') {
                $this->assertEquals('10.00', $discount->amount);

                return;
            }
        }

        $this->fail('Proration when switching to yearly was not done properly.');
    }

    public function test_monthly_to_yearly_properly_prorates()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPY0001')->create($this->getTestToken());

        $this->assertEquals(1, count($owner->subscriptions));
        $this->assertNotNull($owner->subscription('main')->braintree_id);

        // Swap To Monthly
        $owner->subscription('main')->swap('IJPM0001');
        $owner = $owner->fresh();

        // Swap Back To Yearly
        $owner->subscription('main')->swap('IJPY0001');
        $owner = $owner->fresh();

        $this->assertEquals(3, count($owner->subscriptions));
        $this->assertNotNull($owner->subscription('main')->braintree_id);
        $this->assertEquals('IJPY0001', $owner->subscription('main')->braintree_plan);

        $braintreeSubscription = $owner->subscription('main')->asBraintreeSubscription();

        foreach ($braintreeSubscription->discounts as $discount) {
            if ($discount->id === '5tb2') {
                $this->assertEquals('10.00', $discount->amount);

                return;
            }
        }

        $this->fail('Proration when switching to yearly was not done properly.');
    }

    public function test_marking_as_cancelled_from_webhook()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPM0001')->create($this->getTestToken());

        // Perform Request to Webhook
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['kind' => 'SubscriptionCanceled',
            'subscription' => [
                'id' => $owner->subscription('main')->braintree_id,
            ],
        ]));
        $response = (new CashierTestControllerStub)->handleWebhook($request);

        $this->assertEquals(200, $response->getStatusCode());

        $owner = $owner->fresh();
        $subscription = $owner->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function test_marking_subscription_cancelled_on_grace_period_as_cancelled_now_from_webhook()
    {
        $owner = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $owner->newSubscription('main', 'IJPM0001')->create($this->getTestToken());

        // Cancel Subscription
        $subscription = $owner->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->onGracePeriod());

        // Perform Request to Webhook
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['kind' => 'SubscriptionCanceled',
            'subscription' => [
                'id' => $subscription->braintree_id,
            ],
        ]));
        $response = (new CashierTestControllerStub)->handleWebhook($request);

        $this->assertEquals(200, $response->getStatusCode());

        $owner = $owner->fresh();
        $subscription = $owner->subscription('main');

        $this->assertFalse($subscription->onGracePeriod());
    }

    protected function getTestToken()
    {
        return 'fake-valid-nonce';
    }

    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection(): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}

class User extends Eloquent
{
    use Billable;
}

class CashierTestControllerStub extends WebhookController
{
    /**
     * Parse the given Braintree webhook notification request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Braintree\WebhookNotification
     */
    protected function parseBraintreeNotification($request)
    {
        return json_decode($request->getContent());
    }
}

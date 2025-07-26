<?php

namespace SmartRelay\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\PaymentMethod;
use Stripe\Invoice;
use Stripe\WebhookEndpoint;
use SmartRelay\Config\Database;

class BillingService
{
    private $db;
    private $authService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->authService = new AuthService();

        // Initialize Stripe
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');
    }

    public function getSubscriptionPlans(): array
    {
        $plans = $this->db->fetchAll(
            "SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC"
        );

        foreach ($plans as &$plan) {
            $plan['features'] = json_decode($plan['features'] ?? '{}', true);
        }

        return [
            'success' => true,
            'plans' => $plans
        ];
    }

    public function getUserSubscription(array $user): array
    {
        $subscription = $this->db->fetchOne(
            "SELECT us.*, sp.name as plan_name, sp.price, sp.currency, sp.max_devices, sp.max_api_calls, sp.features
             FROM user_subscriptions us
             JOIN subscription_plans sp ON us.plan_id = sp.id
             WHERE us.user_id = :user_id AND us.status = 'active'
             ORDER BY us.created_at DESC LIMIT 1",
            ['user_id' => $user['id']]
        );

        if ($subscription) {
            $subscription['features'] = json_decode($subscription['features'] ?? '{}', true);

            // Get usage statistics
            $usage = $this->getUserUsage($user['id']);
            $subscription['usage'] = $usage;
        }

        return [
            'success' => true,
            'subscription' => $subscription,
            'has_active_subscription' => (bool)$subscription
        ];
    }

    public function createSubscription(array $user, int $planId, string $paymentMethodId): array
    {
        // Check if user already has an active subscription
        $existingSubscription = $this->db->fetchOne(
            "SELECT * FROM user_subscriptions WHERE user_id = :user_id AND status = 'active'",
            ['user_id' => $user['id']]
        );

        if ($existingSubscription) {
            throw new \Exception("User already has an active subscription");
        }

        // Get the plan
        $plan = $this->db->fetchOne(
            "SELECT * FROM subscription_plans WHERE id = :id AND status = 'active'",
            ['id' => $planId]
        );

        if (!$plan) {
            throw new \Exception("Invalid subscription plan");
        }

        try {
            // Create or get Stripe customer
            $stripeCustomer = $this->getOrCreateStripeCustomer($user);

            // Attach payment method to customer
            $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach(['customer' => $stripeCustomer->id]);

            // Set as default payment method
            Customer::update($stripeCustomer->id, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId]
            ]);

            // Create Stripe subscription
            $stripeSubscription = Subscription::create([
                'customer' => $stripeCustomer->id,
                'items' => [[
                    'price_data' => [
                        'currency' => $plan['currency'],
                        'product_data' => [
                            'name' => $plan['name']
                        ],
                        'unit_amount' => $plan['price'] * 100, // Convert to cents
                        'recurring' => [
                            'interval' => $plan['billing_cycle'] === 'yearly' ? 'year' : 'month'
                        ]
                    ]
                ]],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                'expand' => ['latest_invoice.payment_intent']
            ]);

            // Save subscription to database
            $subscriptionId = $this->db->insert('user_subscriptions', [
                'user_id' => $user['id'],
                'plan_id' => $planId,
                'stripe_subscription_id' => $stripeSubscription->id,
                'status' => $stripeSubscription->status,
                'current_period_start' => date('Y-m-d H:i:s', $stripeSubscription->current_period_start),
                'current_period_end' => date('Y-m-d H:i:s', $stripeSubscription->current_period_end)
            ]);

            return [
                'success' => true,
                'subscription_id' => $subscriptionId,
                'stripe_subscription_id' => $stripeSubscription->id,
                'client_secret' => $stripeSubscription->latest_invoice->payment_intent->client_secret,
                'status' => $stripeSubscription->status
            ];

        } catch (\Exception $e) {
            error_log("Subscription creation failed: " . $e->getMessage());
            throw new \Exception("Failed to create subscription: " . $e->getMessage());
        }
    }

    public function cancelSubscription(array $user): array
    {
        $subscription = $this->db->fetchOne(
            "SELECT * FROM user_subscriptions WHERE user_id = :user_id AND status = 'active'",
            ['user_id' => $user['id']]
        );

        if (!$subscription) {
            throw new \Exception("No active subscription found");
        }

        try {
            // Cancel Stripe subscription at period end
            $stripeSubscription = Subscription::update($subscription['stripe_subscription_id'], [
                'cancel_at_period_end' => true
            ]);

            // Update local subscription status
            $this->db->update('user_subscriptions',
                ['status' => 'canceled'],
                'id = :id',
                ['id' => $subscription['id']]
            );

            return [
                'success' => true,
                'message' => 'Subscription will be canceled at the end of the current billing period',
                'cancel_at' => date('Y-m-d H:i:s', $stripeSubscription->current_period_end)
            ];

        } catch (\Exception $e) {
            error_log("Subscription cancellation failed: " . $e->getMessage());
            throw new \Exception("Failed to cancel subscription: " . $e->getMessage());
        }
    }

    public function updateSubscription(array $user, int $newPlanId): array
    {
        $subscription = $this->db->fetchOne(
            "SELECT * FROM user_subscriptions WHERE user_id = :user_id AND status = 'active'",
            ['user_id' => $user['id']]
        );

        if (!$subscription) {
            throw new \Exception("No active subscription found");
        }

        $newPlan = $this->db->fetchOne(
            "SELECT * FROM subscription_plans WHERE id = :id AND status = 'active'",
            ['id' => $newPlanId]
        );

        if (!$newPlan) {
            throw new \Exception("Invalid subscription plan");
        }

        try {
            // Update Stripe subscription
            $stripeSubscription = Subscription::retrieve($subscription['stripe_subscription_id']);

            Subscription::update($subscription['stripe_subscription_id'], [
                'items' => [[
                    'id' => $stripeSubscription->items->data[0]->id,
                    'price_data' => [
                        'currency' => $newPlan['currency'],
                        'product_data' => [
                            'name' => $newPlan['name']
                        ],
                        'unit_amount' => $newPlan['price'] * 100,
                        'recurring' => [
                            'interval' => $newPlan['billing_cycle'] === 'yearly' ? 'year' : 'month'
                        ]
                    ]
                ]],
                'proration_behavior' => 'create_prorations'
            ]);

            // Update local subscription
            $this->db->update('user_subscriptions',
                ['plan_id' => $newPlanId],
                'id = :id',
                ['id' => $subscription['id']]
            );

            return [
                'success' => true,
                'message' => 'Subscription updated successfully'
            ];

        } catch (\Exception $e) {
            error_log("Subscription update failed: " . $e->getMessage());
            throw new \Exception("Failed to update subscription: " . $e->getMessage());
        }
    }

    public function getInvoices(array $user, array $filters = []): array
    {
        $where = ['user_id = :user_id'];
        $params = ['user_id' => $user['id']];

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }

        $limit = (int)($filters['limit'] ?? 20);
        $offset = (int)($filters['offset'] ?? 0);

        $whereClause = implode(' AND ', $where);

        $invoices = $this->db->fetchAll(
            "SELECT i.*, sp.name as plan_name
             FROM invoices i
             LEFT JOIN user_subscriptions us ON i.subscription_id = us.id
             LEFT JOIN subscription_plans sp ON us.plan_id = sp.id
             WHERE {$whereClause}
             ORDER BY i.invoice_date DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM invoices WHERE {$whereClause}",
            $params
        )['total'];

        return [
            'success' => true,
            'invoices' => $invoices,
            'total' => $total
        ];
    }

    public function getUserUsage(int $userId): array
    {
        // Get current period
        $subscription = $this->db->fetchOne(
            "SELECT * FROM user_subscriptions
             WHERE user_id = :user_id AND status = 'active'",
            ['user_id' => $userId]
        );

        $startDate = $subscription ? $subscription['current_period_start'] : date('Y-m-01 00:00:00');
        $endDate = date('Y-m-d H:i:s');

        // Count devices
        $deviceCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM devices WHERE user_id = :user_id",
            ['user_id' => $userId]
        )['count'];

        // Count API calls in current period
        $apiCalls = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM api_usage
             WHERE user_id = :user_id AND timestamp >= :start_date",
            ['user_id' => $userId, 'start_date' => $startDate]
        )['count'];

        return [
            'devices' => $deviceCount,
            'api_calls' => $apiCalls,
            'period_start' => $startDate,
            'period_end' => $endDate
        ];
    }

    public function handleWebhook(array $payload, string $signature): array
    {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent(
                json_encode($payload),
                $signature,
                $webhookSecret
            );

            switch ($event['type']) {
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($event['data']['object']);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event['data']['object']);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaymentSucceeded($event['data']['object']);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($event['data']['object']);
                    break;

                default:
                    error_log("Unhandled webhook event: " . $event['type']);
            }

            return ['success' => true];

        } catch (\Exception $e) {
            error_log("Webhook handling failed: " . $e->getMessage());
            throw new \Exception("Webhook handling failed");
        }
    }

    private function getOrCreateStripeCustomer(array $user): \Stripe\Customer
    {
        // Check if customer already exists
        $customers = Customer::all(['email' => $user['email'], 'limit' => 1]);

        if (!empty($customers->data)) {
            return $customers->data[0];
        }

        // Create new customer
        return Customer::create([
            'email' => $user['email'],
            'name' => $user['username'],
            'metadata' => [
                'user_id' => $user['id']
            ]
        ]);
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $this->db->update('user_subscriptions',
            [
                'status' => $subscription['status'],
                'current_period_start' => date('Y-m-d H:i:s', $subscription['current_period_start']),
                'current_period_end' => date('Y-m-d H:i:s', $subscription['current_period_end'])
            ],
            'stripe_subscription_id = :stripe_id',
            ['stripe_id' => $subscription['id']]
        );
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $this->db->update('user_subscriptions',
            ['status' => 'canceled'],
            'stripe_subscription_id = :stripe_id',
            ['stripe_id' => $subscription['id']]
        );
    }

    private function handleInvoicePaymentSucceeded($invoice): void
    {
        // Update invoice status
        $this->db->update('invoices',
            [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s', $invoice['status_transitions']['paid_at'])
            ],
            'stripe_invoice_id = :stripe_id',
            ['stripe_id' => $invoice['id']]
        );

        // Send payment confirmation email
        $this->sendPaymentConfirmationEmail($invoice);
    }

    private function handleInvoicePaymentFailed($invoice): void
    {
        // Update invoice status
        $this->db->update('invoices',
            ['status' => 'uncollectible'],
            'stripe_invoice_id = :stripe_id',
            ['stripe_id' => $invoice['id']]
        );

        // Send payment failure notification
        $this->sendPaymentFailureEmail($invoice);
    }

    private function sendPaymentConfirmationEmail($invoice): void
    {
        // TODO: Implement email sending
        error_log("Payment confirmation email needed for invoice: " . $invoice['id']);
    }

    private function sendPaymentFailureEmail($invoice): void
    {
        // TODO: Implement email sending
        error_log("Payment failure email needed for invoice: " . $invoice['id']);
    }

    public function generateUsageReport(array $user, string $month = null): array
    {
        $month = $month ?: date('Y-m');
        $startDate = $month . '-01 00:00:00';
        $endDate = date('Y-m-t 23:59:59', strtotime($startDate));

        // API usage by day
        $apiUsage = $this->db->fetchAll(
            "SELECT DATE(timestamp) as date, COUNT(*) as calls
             FROM api_usage
             WHERE user_id = :user_id AND timestamp BETWEEN :start_date AND :end_date
             GROUP BY DATE(timestamp)
             ORDER BY date",
            ['user_id' => $user['id'], 'start_date' => $startDate, 'end_date' => $endDate]
        );

        // Device activity
        $deviceActivity = $this->db->fetchAll(
            "SELECT d.name, COUNT(dl.id) as log_count
             FROM devices d
             LEFT JOIN device_logs dl ON d.id = dl.device_id
                AND dl.timestamp BETWEEN :start_date AND :end_date
             WHERE d.user_id = :user_id
             GROUP BY d.id, d.name",
            ['user_id' => $user['id'], 'start_date' => $startDate, 'end_date' => $endDate]
        );

        return [
            'success' => true,
            'month' => $month,
            'api_usage' => $apiUsage,
            'device_activity' => $deviceActivity,
            'summary' => [
                'total_api_calls' => array_sum(array_column($apiUsage, 'calls')),
                'active_devices' => count(array_filter($deviceActivity, fn($d) => $d['log_count'] > 0))
            ]
        ];
    }
}

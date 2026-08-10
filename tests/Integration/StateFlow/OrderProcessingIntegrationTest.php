<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Integration\StateFlow;

use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Configuration\Configuration;
use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\StateFlow;
use CoverGenius\StateFlow\Tests\Utils\ExecutionLogger;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

/**
 * Scenario 14.1: Full order processing workflow
 */
class OrderProcessingIntegrationTest extends TestCase
{
    use CreatesTestActions;
    use CreatesTestGates;
    use CreatesTestStates;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    public function testOrderTransitionsToProcessingWhenAllGatesAllow(): void
    {
        $initialState = $this->createTestState([
            'status' => 'pending',
            'order_id' => 'ORD-1',
            'payment_status' => 'paid',
            'stock_available' => true,
            'shipping_address' => '123 Main St',
        ]);
        $processingState = $this->createTestState([
            'status' => 'processing',
            'order_id' => 'ORD-1',
            'payment_status' => 'paid',
            'stock_available' => true,
            'shipping_address' => '123 Main St',
        ]);

        $paymentGate = $this->createTestGate('PaymentProcessed', GateResult::ALLOW);
        $stockGate = $this->createTestGate('ItemsInStock', GateResult::ALLOW);
        $addressGate = $this->createTestGate('ValidShippingAddress', GateResult::ALLOW);

        $reserveInventoryAction = $this->createTestAction('ReserveInventory');
        $createShipmentAction = $this->createTestActionWithState('CreateShipment', $processingState);
        $sendConfirmationEmailAction = $this->createTestAction('SendConfirmationEmail');

        $stateFlow = new StateFlow(function (State $state, Delta $delta) use (
            $paymentGate,
            $stockGate,
            $addressGate,
            $reserveInventoryAction,
            $createShipmentAction,
            $sendConfirmationEmailAction
        ): Configuration {
            return Configuration::fromArray(
                [$paymentGate, $stockGate, $addressGate],
                [$reserveInventoryAction, $createShipmentAction, $sendConfirmationEmailAction],
            );
        });

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'processing']))
            ->execute();

        $this->assertTrue($context->executionStatus()->isCompleted());

        $gateEvaluations = $context->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(3, $gateEvaluations);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[1]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[2]->result);

        $this->assertCount(3, $context->executionHistory()->getActionExecutions());
        $this->assertCount(0, $context->executionHistory()->getActionSkips());

        $lastGateIndex = array_search('Gate:ValidShippingAddress', $this->logger->log, true);
        $firstActionIndex = array_search('Action:ReserveInventory', $this->logger->log, true);
        $this->assertLessThan($firstActionIndex, $lastGateIndex, 'All gates should execute before any actions');

        $this->assertSame('processing', $context->getCurrentState()->toArray()['status']);
    }

    public function testNoActionsExecuteWhenAGateDeniesTheTransition(): void
    {
        $initialState = $this->createTestState([
            'status' => 'pending',
            'order_id' => 'ORD-2',
            'payment_status' => 'paid',
            'stock_available' => false,
            'shipping_address' => '123 Main St',
        ]);

        $paymentGate = $this->createTestGate('PaymentProcessed', GateResult::ALLOW);
        $stockGate = $this->createTestGate('ItemsInStock', GateResult::DENY);
        $addressGate = $this->createTestGate('ValidShippingAddress', GateResult::ALLOW);

        $reserveInventoryAction = $this->createTestAction('ReserveInventory');
        $createShipmentAction = $this->createTestAction('CreateShipment');
        $sendConfirmationEmailAction = $this->createTestAction('SendConfirmationEmail');

        $stateFlow = new StateFlow(fn () => Configuration::fromArray(
            [$paymentGate, $stockGate, $addressGate],
            [$reserveInventoryAction, $createShipmentAction, $sendConfirmationEmailAction],
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'processing']))
            ->execute();

        $gateEvaluations = $context->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(2, $gateEvaluations, 'Gate evaluation should short-circuit after the denying gate');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::DENY, $gateEvaluations[1]->result);

        $this->assertCount(0, $context->executionHistory()->getActionExecutions());
        $this->assertCount(3, $context->executionHistory()->getActionSkips());

        $this->assertNotContains('Gate:ValidShippingAddress', $this->logger->log, 'Gate after the denial should not run');
        $this->assertNotContains('Action:ReserveInventory', $this->logger->log);
        $this->assertNotContains('Action:CreateShipment', $this->logger->log);
        $this->assertNotContains('Action:SendConfirmationEmail', $this->logger->log);

        $this->assertSame('pending', $context->getCurrentState()->toArray()['status']);
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}

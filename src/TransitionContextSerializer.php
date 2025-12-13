<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ActionResultCollection;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;
use JsonException;

/**
 * Handles serialization and deserialization of TransitionContext
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Serializer must know all domain objects and factories (17 dependencies, threshold 13)
 */
class TransitionContextSerializer
{
    /**
     * Serialize a transition context to JSON
     */
    public function serialize(TransitionContext $context): string
    {
        $data = [
            'initialState' => $context->getInitialState()->toArray(),
            'currentState' => $context->getCurrentState()->toArray(),
            'desiredDelta' => $context->getDesiredDelta()->asArray(),
            'executionStatus' => $this->serializeExecutionStatus($context),
            'lockState' => $context->lockState()->toArray(),
            'configuration' => $this->serializeConfiguration($context->getConfiguration()),
            'gateEvaluations' => $this->serializeGateEvaluations($context),
            'actionExecutions' => $this->serializeActionExecutions($context),
            'actionSkips' => $this->serializeActionSkips($context),
        ];

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize a transition context from JSON
     *
     * @throws JsonException
     */
    public function unserialize(
        string $data,
        StateFactory $stateFactory,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): TransitionContext {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('Invalid JSON data: expected object');
        }

        // Reconstruct states
        $initialState = $stateFactory->fromArray($decoded['initialState'] ?? []);
        $currentState = $stateFactory->fromArray($decoded['currentState'] ?? []);

        // Reconstruct configuration
        $configuration = $this->restoreConfiguration($decoded, $actionFactory, $gateFactory);

        // Create new context
        /** @var array<string, mixed> $deltaData */
        $deltaData = $decoded['desiredDelta'] ?? [];
        $desiredDelta = new ArrayDelta($deltaData);
        $context = new TransitionContext($initialState, $desiredDelta, $configuration);

        // Restore execution history
        $gateEvaluations = $this->restoreGateEvaluationsCollection($decoded, $gateFactory);
        $actionExecutions = $this->restoreActionExecutionsCollection($decoded, $stateFactory);
        $actionSkips = $this->restoreActionSkipsCollection($decoded, $actionFactory);

        $history = new ExecutionHistory(
            $initialState,
            $gateEvaluations,
            $actionExecutions,
            $actionSkips,
            $currentState
        );
        $context->setExecutionHistory($history);

        // Restore execution status
        $this->restoreExecutionStatus($context, $decoded);

        // Restore lock state
        $lockStateData = $decoded['lockState'] ?? [];
        $context->setLockState(is_array($lockStateData) ? LockState::fromArray($lockStateData) : new LockState());

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExecutionStatus(TransitionContext $context): array
    {
        return [
            'completed' => $context->executionStatus()->isCompleted(),
            'paused' => $context->executionStatus()->isPaused(),
            'stopped' => $context->executionStatus()->isStopped(),
            'skippedDueToLock' => $context->executionStatus()->wasSkippedDueToLock(),
            'metadata' => $context->getStatusMetadata(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function serializeConfiguration(Configuration $configuration): array
    {
        return [
            'transitionGates' => array_map(
                fn ($gate) => get_class($gate),
                $configuration->transitionGates->toArray()
            ),
            'actions' => array_map(
                fn ($action) => get_class($action),
                $configuration->actions->toArray()
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeGateEvaluations(TransitionContext $context): array
    {
        return array_map(
            fn (GateEvaluation $eval) => [
                'gate' => get_class($eval->gate),
                'result' => $eval->result->name,
                'isActionGate' => $eval->isActionGate,
                'timestamp' => $eval->timestamp,
            ],
            $context->executionHistory()->getGateEvaluations()->toArray()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeActionExecutions(TransitionContext $context): array
    {
        return array_map(
            fn (ActionResult $result) => [
                'executionState' => $result->executionState->name,
                'newState' => $result->newState?->toArray(),
                'metadata' => $result->metadata,
            ],
            $context->executionHistory()->getActionExecutions()->toArray()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeActionSkips(TransitionContext $context): array
    {
        return array_map(
            fn (ActionSkip $skip) => [
                'action' => get_class($skip->action),
                'gateResult' => $skip->gateResult->name,
                'timestamp' => $skip->timestamp,
            ],
            $context->executionHistory()->getActionSkips()->toArray()
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreConfiguration(
        array $decoded,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): Configuration {
        $configData = $decoded['configuration'] ?? [];

        /** @var array<int, string> $gateClassNames */
        $gateClassNames = is_array($configData) ? ($configData['transitionGates'] ?? []) : [];
        $transitionGates = array_map(
            fn (string $className) => $gateFactory->fromClassName($className),
            $gateClassNames
        );

        /** @var array<int, string> $actionClassNames */
        $actionClassNames = is_array($configData) ? ($configData['actions'] ?? []) : [];
        $actions = array_map(
            fn (string $className) => $actionFactory->fromClassName($className),
            $actionClassNames
        );

        return new Configuration($transitionGates, $actions);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreExecutionStatus(TransitionContext $context, array $decoded): void
    {
        if (isset($decoded['executionStatus']) && is_array($decoded['executionStatus'])) {
            $this->restoreNewFormatExecutionStatus($context, $decoded['executionStatus']);

            return;
        }

        if (isset($decoded['status']) && is_string($decoded['status'])) {
            $this->restoreLegacyFormatExecutionStatus($context, $decoded);
        }
    }

    /**
     * @param array<string, mixed> $statusData
     */
    private function restoreNewFormatExecutionStatus(TransitionContext $context, array $statusData): void
    {
        $metadata = $statusData['metadata'] ?? null;

        if ($statusData['completed'] ?? false) {
            $context->executionStatus()->markCompleted();
        } elseif ($statusData['paused'] ?? false) {
            $context->executionStatus()->markPaused($metadata);
        } elseif ($statusData['stopped'] ?? false) {
            $context->executionStatus()->markStopped($metadata);
        }

        if ($statusData['skippedDueToLock'] ?? false) {
            $context->executionStatus()->markSkippedDueToLock();
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreLegacyFormatExecutionStatus(TransitionContext $context, array $decoded): void
    {
        match ($decoded['status']) {
            'CONTINUE' => $context->executionStatus()->markCompleted(),
            'PAUSE' => $context->executionStatus()->markPaused(),
            'STOP' => $context->executionStatus()->markStopped(),
            default => null,
        };

        if ($decoded['skippedDueToLock'] ?? false) {
            $context->executionStatus()->markSkippedDueToLock();
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreGateEvaluationsCollection(array $decoded, GateFactory $gateFactory): GateEvaluationCollection
    {
        /** @var array<int, array{gate: string, result: string, isActionGate: bool, timestamp?: float}> $gateEvaluationsData */
        $gateEvaluationsData = $decoded['gateEvaluations'] ?? [];
        $evaluations = [];

        foreach ($gateEvaluationsData as $evalData) {
            $gate = $gateFactory->fromClassName($evalData['gate']);
            $result = match ($evalData['result']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $timestamp = $evalData['timestamp'] ?? 0.0;
            $evaluations[] = new GateEvaluation($gate, $result, $evalData['isActionGate'], $timestamp);
        }

        return GateEvaluationCollection::fromArray($evaluations);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreActionExecutionsCollection(array $decoded, StateFactory $stateFactory): ActionResultCollection
    {
        /** @var array<int, array{executionState: string, newState: array<string, mixed>|null, metadata: mixed}> $actionExecutionsData */
        $actionExecutionsData = $decoded['actionExecutions'] ?? [];
        $results = [];

        foreach ($actionExecutionsData as $actionData) {
            $executionState = match ($actionData['executionState']) {
                'CONTINUE' => ExecutionState::CONTINUE,
                'PAUSE' => ExecutionState::PAUSE,
                'STOP' => ExecutionState::STOP,
                default => ExecutionState::CONTINUE,
            };
            $newState = $actionData['newState'] !== null
                ? $stateFactory->fromArray($actionData['newState'])
                : null;
            $results[] = new ActionResult($executionState, $newState, $actionData['metadata']);
        }

        return ActionResultCollection::fromArray($results);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreActionSkipsCollection(array $decoded, ActionFactory $actionFactory): ActionSkipCollection
    {
        /** @var array<int, array{action: string, gateResult: string, timestamp?: float}> $actionSkipsData */
        $actionSkipsData = $decoded['actionSkips'] ?? [];
        $skips = [];

        foreach ($actionSkipsData as $skipData) {
            $action = $actionFactory->fromClassName($skipData['action']);
            $gateResult = match ($skipData['gateResult']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $timestamp = $skipData['timestamp'] ?? 0.0;
            $skips[] = new ActionSkip($action, $gateResult, $timestamp);
        }

        return ActionSkipCollection::fromArray($skips);
    }
}

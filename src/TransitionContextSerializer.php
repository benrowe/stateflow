<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\ActionResultCollection;
use CoverGenius\StateFlow\Action\ExecutionState;
use CoverGenius\StateFlow\Configuration\Configuration;
use CoverGenius\StateFlow\Configuration\ConfigurationFactory;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\Locking\LockState;
use JsonException;

/**
 * Handles serialization and deserialization of TransitionContext
 *
 * Serializer must know-all domain objects and factories (17 dependencies, threshold 13)
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
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
        $decoded = $this->decode($data);

        // Reconstruct states
        /** @var array<string, mixed> $initialStateData */
        $initialStateData = $decoded['initialState'] ?? [];
        $initialState = $stateFactory->fromArray($initialStateData);

        /** @var array<string, mixed> $currentStateData */
        $currentStateData = $decoded['currentState'] ?? [];
        $currentState = $stateFactory->fromArray($currentStateData);

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
        $rawLockState = $decoded['lockState'] ?? [];
        if (!is_array($rawLockState)) {
            $context->setLockState(new LockState());

            return $context;
        }

        /** @var array<string, mixed> $lockStateData */
        $lockStateData = $rawLockState;
        $context->setLockState(LockState::fromArray($lockStateData));

        return $context;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decode(string $data): array
    {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('Invalid JSON data: expected object');
        }

        // @phpstan-ignore-next-line - we know it's an array
        return $decoded;
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
            'yielded' => $context->executionStatus()->isYielded(),
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
            'transitionGates' => array_values(array_map(
                fn ($gate) => get_class($gate),
                $configuration->transitionGates->toArray()
            )),
            'actions' => array_values(array_map(
                fn ($action) => get_class($action),
                $configuration->actions->toArray()
            )),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeGateEvaluations(TransitionContext $context): array
    {
        return array_values(array_map(
            fn (GateEvaluation $eval) => [
                'gate' => get_class($eval->gate),
                'result' => $eval->result->name,
                'isActionGate' => $eval->isActionGate,
                'timestamp' => $eval->timestamp,
            ],
            $context->executionHistory()->getGateEvaluations()->toArray()
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeActionExecutions(TransitionContext $context): array
    {
        return array_values(array_map(
            fn (ActionResult $result) => [
                'executionState' => $result->executionState->name,
                'newState' => $result->newState?->toArray(),
                'metadata' => $result->metadata,
            ],
            $context->executionHistory()->getActionExecutions()->toArray()
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeActionSkips(TransitionContext $context): array
    {
        return array_values(array_map(
            fn (ActionSkip $skip) => [
                'action' => get_class($skip->action),
                'gateResult' => $skip->gateResult->name,
                'timestamp' => $skip->timestamp,
            ],
            $context->executionHistory()->getActionSkips()->toArray()
        ));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreConfiguration(
        array $decoded,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): Configuration {
        $rawConfigData = $decoded['configuration'] ?? [];
        /** @var array<string, mixed> $configData */
        $configData = is_array($rawConfigData) ? $rawConfigData : [];

        $rawGateClassNames = $configData['transitionGates'] ?? [];
        /** @var array<int, string> $gateClassNames */
        $gateClassNames = is_array($rawGateClassNames) ? $rawGateClassNames : [];
        $transitionGates = array_map(
            fn (string $className) => $gateFactory->fromClassName($className),
            $gateClassNames
        );

        $rawActionClassNames = $configData['actions'] ?? [];
        /** @var array<int, string> $actionClassNames */
        $actionClassNames = is_array($rawActionClassNames) ? $rawActionClassNames : [];
        $actions = array_map(
            fn (string $className) => $actionFactory->fromClassName($className),
            $actionClassNames
        );

        return (new ConfigurationFactory())->makeFromArray($transitionGates, $actions);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreExecutionStatus(TransitionContext $context, array $decoded): void
    {
        $rawExecutionStatus = $decoded['executionStatus'] ?? null;
        if (is_array($rawExecutionStatus)) {
            /** @var array<string, mixed> $executionStatusData */
            $executionStatusData = $rawExecutionStatus;
            $this->restoreNewFormatExecutionStatus($context, $executionStatusData);

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
        } elseif ($statusData['yielded'] ?? false) {
            $context->executionStatus()->markYielded($metadata);
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
        $rawGateEvaluations = $decoded['gateEvaluations'] ?? [];
        /** @var array<int, array{gate: string, result: string, isActionGate: bool, timestamp?: float}> $gateEvaluationsData */
        $gateEvaluationsData = is_array($rawGateEvaluations) ? $rawGateEvaluations : [];
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
        $rawActionExecutions = $decoded['actionExecutions'] ?? [];
        /** @var array<int, array{executionState: string, newState: array<string, mixed>|null, metadata: mixed}> $actionExecutionsData */
        $actionExecutionsData = is_array($rawActionExecutions) ? $rawActionExecutions : [];
        $results = [];

        foreach ($actionExecutionsData as $actionData) {
            $executionState = match ($actionData['executionState']) {
                'CONTINUE' => ExecutionState::CONTINUE,
                'PAUSE' => ExecutionState::PAUSE,
                'STOP' => ExecutionState::STOP,
                'YIELD' => ExecutionState::YIELD,
                default => ExecutionState::CONTINUE,
            };
            $newState = null;
            if ($actionData['newState'] !== null) {
                /** @var array<string, mixed> $newStateData */
                $newStateData = $actionData['newState'];
                $newState = $stateFactory->fromArray($newStateData);
            }
            $results[] = new ActionResult($executionState, $newState, $actionData['metadata']);
        }

        return ActionResultCollection::fromArray($results);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function restoreActionSkipsCollection(array $decoded, ActionFactory $actionFactory): ActionSkipCollection
    {
        $rawActionSkips = $decoded['actionSkips'] ?? [];
        /** @var array<int, array{action: string, gateResult: string, timestamp?: float}> $actionSkipsData */
        $actionSkipsData = is_array($rawActionSkips) ? $rawActionSkips : [];
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

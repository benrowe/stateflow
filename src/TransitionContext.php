<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;

class TransitionContext
{
    /**
     * @var ActionResult[]
     */
    private array $actions = [];

    /**
     * @var GateEvaluation[]
     */
    private array $gateEvaluations = [];

    /**
     * @var ActionSkip[]
     */
    private array $actionSkips = [];

    private ?ExecutionState $status = null;

    private State $currentState;

    private LockState $lockState;

    private bool $skippedDueToLock = false;

    /**
     * @param array<string, mixed> $desiredDelta
     */
    public function __construct(
        private readonly State $initialState,
        private readonly array $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->currentState = $initialState;
        $this->lockState = new LockState();
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    public function getInitialState(): State
    {
        return $this->initialState;
    }

    public function updateCurrentState(State $newState): void
    {
        $this->currentState = $newState;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDesiredDelta(): array
    {
        return $this->desiredDelta;
    }

    /**
     * @return ActionResult[]
     */
    public function getActionExecutions(): array
    {
        return $this->actions;
    }

    public function addActionResult(ActionResult $actionResult): void
    {
        $this->actions[] = $actionResult;
    }

    /**
     * @return GateEvaluation[]
     */
    public function getGateEvaluations(): array
    {
        return $this->gateEvaluations;
    }

    public function addGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->gateEvaluations[] = new GateEvaluation($gate, $result, $isActionGate);
    }

    public function didGatesPass(): bool
    {
        $availableGates = count($this->getConfiguration()->getTransitionGates());
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->getGateEvaluations())) {
            return false;
        }
        foreach ($this->getGateEvaluations() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ActionSkip[]
     */
    public function getActionSkips(): array
    {
        return $this->actionSkips;
    }

    public function addActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->actionSkips[] = new ActionSkip($action, $gateResult);
    }

    public function markAsCompleted(): void
    {
        $this->status = ExecutionState::CONTINUE;
    }

    public function markAsPaused(): void
    {
        $this->status = ExecutionState::PAUSE;
    }

    public function markAsStopped(): void
    {
        $this->status = ExecutionState::STOP;
    }

    public function clearPauseStatus(): void
    {
        if ($this->status === ExecutionState::PAUSE) {
            $this->status = null;
        }
    }

    public function isPaused(): bool
    {
        return $this->status === ExecutionState::PAUSE;
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionState::CONTINUE;
    }

    public function isStopped(): bool
    {
        return $this->status === ExecutionState::STOP;
    }

    public function getLockState(): LockState
    {
        return $this->lockState;
    }

    public function setLockState(LockState $lockState): void
    {
        $this->lockState = $lockState;
    }

    public function markAsSkippedDueToLock(): void
    {
        $this->skippedDueToLock = true;
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->skippedDueToLock;
    }

    /**
     * Get metadata from the last PAUSE or STOP action.
     *
     * Returns null if no PAUSE/STOP action has been executed or if the action had no metadata.
     */
    public function getStatusMetadata(): mixed
    {
        // Find the last action that caused PAUSE or STOP
        foreach (array_reverse($this->actions) as $actionResult) {
            if ($actionResult->executionState === ExecutionState::PAUSE
                || $actionResult->executionState === ExecutionState::STOP
            ) {
                return $actionResult->metadata;
            }
        }

        return null;
    }

    /**
     * Serialize the context to a JSON string for persistence.
     */
    public function serialize(): string
    {
        $data = [
            'initialState' => $this->initialState->toArray(),
            'currentState' => $this->currentState->toArray(),
            'desiredDelta' => $this->desiredDelta,
            'status' => $this->status?->value,
            'skippedDueToLock' => $this->skippedDueToLock,
            'lockState' => $this->lockState->toArray(),
            'configuration' => [
                'transitionGates' => array_map(
                    fn($gate) => get_class($gate),
                    $this->configuration->getTransitionGates()
                ),
                'actions' => array_map(
                    fn($action) => get_class($action),
                    $this->configuration->getActions()
                ),
            ],
            'gateEvaluations' => array_map(
                fn(GateEvaluation $eval) => [
                    'gate' => get_class($eval->gate),
                    'result' => $eval->result->value,
                    'isActionGate' => $eval->isActionGate,
                ],
                $this->gateEvaluations
            ),
            'actions' => array_map(
                fn(ActionResult $result) => [
                    'executionState' => $result->executionState->value,
                    'newState' => $result->newState?->toArray(),
                    'metadata' => $result->metadata,
                ],
                $this->actions
            ),
            'actionSkips' => array_map(
                fn(ActionSkip $skip) => [
                    'action' => get_class($skip->action),
                    'gateResult' => $skip->gateResult->value,
                ],
                $this->actionSkips
            ),
        ];

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize a context from a JSON string.
     *
     * @throws \JsonException
     */
    public static function unserialize(
        string $data,
        StateFactory $stateFactory,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): self {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        // Reconstruct states
        $initialState = $stateFactory->fromArray($decoded['initialState']);
        $currentState = $stateFactory->fromArray($decoded['currentState']);

        // Reconstruct configuration
        $transitionGates = array_map(
            fn(string $className) => $gateFactory->fromClassName($className),
            $decoded['configuration']['transitionGates']
        );
        $actions = array_map(
            fn(string $className) => $actionFactory->fromClassName($className),
            $decoded['configuration']['actions']
        );
        $configuration = new Configuration($transitionGates, $actions);

        // Create new context
        $context = new self($initialState, $decoded['desiredDelta'], $configuration);

        // Restore current state
        $context->currentState = $currentState;

        // Restore status
        if ($decoded['status'] !== null) {
            $context->status = ExecutionState::from($decoded['status']);
        }

        // Restore lock state
        $context->lockState = LockState::fromArray($decoded['lockState']);
        $context->skippedDueToLock = $decoded['skippedDueToLock'];

        // Restore gate evaluations
        foreach ($decoded['gateEvaluations'] as $evalData) {
            $gate = $gateFactory->fromClassName($evalData['gate']);
            $result = GateResult::from($evalData['result']);
            $context->gateEvaluations[] = new GateEvaluation(
                $gate,
                $result,
                $evalData['isActionGate']
            );
        }

        // Restore action executions
        foreach ($decoded['actions'] as $actionData) {
            $executionState = ExecutionState::from($actionData['executionState']);
            $newState = $actionData['newState'] !== null
                ? $stateFactory->fromArray($actionData['newState'])
                : null;
            $context->actions[] = new ActionResult(
                $executionState,
                $newState,
                $actionData['metadata']
            );
        }

        // Restore action skips
        foreach ($decoded['actionSkips'] as $skipData) {
            $action = $actionFactory->fromClassName($skipData['action']);
            $gateResult = GateResult::from($skipData['gateResult']);
            $context->actionSkips[] = new ActionSkip($action, $gateResult);
        }

        return $context;
    }
}

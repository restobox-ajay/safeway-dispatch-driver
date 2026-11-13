<?php

declare(strict_types=1);

namespace App\Htaccess;

final readonly class HtaccessLockSelfTestReport
{
    /** @param list<array{label:string,passed:bool,detail:string}> $steps */
    public function __construct(
        public array $steps,
        public int $ranAt,
    ) {
    }

    public function passed(): bool
    {
        if ($this->steps === []) {
            return false;
        }
        foreach ($this->steps as $step) {
            if (!$step['passed']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{steps:list<array{label:string,passed:bool,detail:string}>,ran_at:int} */
    public function toArray(): array
    {
        return ['steps' => $this->steps, 'ran_at' => $this->ranAt];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!isset($data['steps'], $data['ran_at']) || !\is_array($data['steps'])) {
            return null;
        }

        return new self($data['steps'], (int) $data['ran_at']);
    }
}

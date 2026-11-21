<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\Commands\SubmitAssembly;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;

/**
 * Saves or confirms a player's finished faction: the swaps they chose, the surplus they dropped
 * and the slice they laid out.
 */
class HandleFrankenAssemblyRequest extends FrankenRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadFrankenDraftByUrlId();

        if ($draft === null) {
            return $this->notAFrankenDraft();
        }

        $playerId = $this->actingPlayer($draft);

        if ($playerId === null) {
            return $this->notAllowed();
        }

        $swaps = $this->stringMap((array) $this->request->get('swaps', []));
        $dropped = $this->stringList((array) $this->request->get('dropped', []));
        $slice = $this->stringList((array) $this->request->get('slice', []));
        $confirm = $this->request->get('confirm') === 'on';

        return $this->mutateAndRespond(
            $draft,
            static function (FrankenDraft $fresh) use ($playerId, $swaps, $dropped, $slice, $confirm): void {
                (new SubmitAssembly($fresh, $playerId, $swaps, $dropped, $slice, $confirm))->handle();
            },
        );
    }

    /** @return array<string, string> */
    private function stringMap(array $input): array
    {
        $map = [];

        foreach ($input as $key => $value) {
            if (is_scalar($value)) {
                $map[(string) $key] = (string) $value;
            }
        }

        return $map;
    }

    /** @return array<int, string> */
    private function stringList(array $input): array
    {
        $list = [];

        foreach ($input as $value) {
            if (is_scalar($value)) {
                $list[] = (string) $value;
            }
        }

        return $list;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Draft\Name;
use App\Draft\Seed;
use App\Draft\Settings;
use App\Franken\Commands\GenerateFrankenDraft;
use App\Franken\DraftCategory;
use App\Franken\DraftOrderMode;
use App\Franken\Exceptions\InsufficientComponentsException;
use App\Franken\Exceptions\InvalidFrankenSettingsException;
use App\Franken\FrankenDraft;
use App\Franken\FrankenSettings;
use App\Franken\SliceAssemblyMode;
use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Http\RequestHandler;
use App\TwilightImperium\AllianceTeamMode;
use App\TwilightImperium\AllianceTeamPosition;
use App\TwilightImperium\Edition;

class HandleGenerateDraftRequest extends RequestHandler
{
    private Settings $settings;

    public function __construct(HttpRequest $request)
    {
        parent::__construct($request);

        // Franken settings are a different shape entirely, so they are only parsed when asked for.
        if (! $this->isFrankenMode()) {
            // parse settings from request
            $this->settings = $this->settingsFromRequest();
        }
    }

    public function handle(): HttpResponse
    {
        if ($this->isFrankenMode()) {
            return $this->handleFranken();
        }

        try {
            $this->settings->validate();
        } catch (InvalidDraftSettingsException $e) {
            return $this->error($e->getMessage(), 400);
        }

        $draft = dispatch(new GenerateDraft($this->settingsFromRequest()));

        app()->repository->save($draft);

        return $this->json([
            'id' => $draft->id,
            'admin' => $draft->secrets->adminSecret,
        ]);
    }

    private function isFrankenMode(): bool
    {
        return $this->request->get('mode') === FrankenDraft::MODE;
    }

    private function handleFranken(): HttpResponse
    {
        $settings = $this->frankenSettingsFromRequest();

        try {
            $settings->validate();
        } catch (InvalidFrankenSettingsException $e) {
            return $this->error($e->getMessage(), 400);
        }

        try {
            $draft = dispatch(new GenerateFrankenDraft($settings));
        } catch (InsufficientComponentsException $e) {
            return $this->error($e->getMessage(), 400);
        }

        app()->repository->save($draft);

        return $this->json([
            'id' => $draft->id,
            'admin' => $draft->secrets->adminSecret,
        ]);
    }

    private function frankenSettingsFromRequest(): FrankenSettings
    {
        $playerNames = [];

        for ($i = 0; $i < (int) $this->request->get('num_players'); $i++) {
            $playerNames[] = trim($this->request->get('player')[$i] ?? '');
        }

        $sources = [];

        foreach ((array) $this->request->get('sources', []) as $source) {
            if (is_scalar($source) && (string) $source !== '') {
                $sources[] = (string) $source;
            }
        }

        return new FrankenSettings(
            $playerNames,
            new Name($this->request->get('game_name')),
            new Seed($this->request->get('seed') != null ? (int) $this->request->get('seed') : null),
            $this->request->get('preset_draft_order') == 'on',
            $sources,
            DraftOrderMode::tryFrom((string) $this->request->get('draft_order_mode', 'drafted'))
                ?? DraftOrderMode::DRAFTED,
            [],
            $this->frankenLimitsFromRequest(),
            (int) $this->request->get('picks_first_round', FrankenSettings::DEFAULT_PICKS_FIRST_ROUND),
            (int) $this->request->get('picks_per_round', FrankenSettings::DEFAULT_PICKS_PER_ROUND),
            SliceAssemblyMode::tryFrom((string) $this->request->get('slice_assembly_mode', 'draft'))
                ?? SliceAssemblyMode::DRAFT,
        );
    }

    /**
     * Per-category bag/hand overrides, posted as limits[CATEGORY][bag|hand]. A category left blank
     * (or not a real category) falls back to the standard Franken default in FrankenSettings::limit,
     * so only what the admin actually changed needs to travel here.
     *
     * @return array<string, array<string, int>>
     */
    private function frankenLimitsFromRequest(): array
    {
        $limits = [];

        foreach ((array) $this->request->get('limits', []) as $categoryValue => $values) {
            $category = DraftCategory::tryFrom((string) $categoryValue);

            if ($category === null || ! is_array($values)) {
                continue;
            }

            foreach (['bag', 'hand'] as $key) {
                if (! isset($values[$key]) || $values[$key] === '') {
                    continue;
                }

                $limits[$category->value][$key] = max(0, (int) $values[$key]);
            }

            // The final (assembly) limit is not exposed separately in the UI: standard Franken has
            // nothing to trim, so it always tracks whatever hand limit the admin chose.
            if (isset($limits[$category->value]['hand'])) {
                $limits[$category->value]['final'] = $limits[$category->value]['hand'];
            }
        }

        return $limits;
    }

    private function settingsFromRequest(): Settings
    {
        $playerNames = [];
        for ($i = 0; $i < $this->request->get('num_players'); $i++) {
            $playerNames[] = trim($this->request->get('player')[$i] ?? '');
        }

        $allianceMode = (bool) $this->request->get('alliance_on', false);

        $customSlices = [];
        if ($this->request->get('custom_slices', '') != '') {
            $sliceData = explode("\n", $this->request->get('custom_slices'));
            foreach ($sliceData as $s) {
                $slice = [];
                $t = explode(',', $s);
                foreach ($t as $tile) {
                    $tile = ltrim(trim($tile), '0');
                    $slice[] = $tile;
                }
                $customSlices[] = $slice;
            }
        }

        return new Settings(
            $playerNames,
            $this->request->get('preset_draft_order') == 'on',
            new Name($this->request->get('game_name')),
            new Seed($this->request->get('seed') != null ? (int) $this->request->get('seed') : null),
            (int) $this->request->get('num_slices'),
            (int) $this->request->get('num_factions'),
            $this->tileSetsFromRequest(),
            $this->factionSetsFromRequest(),
            $this->request->get('include_keleres') == 'on',
            $this->request->get('wormholes') == 'on',
            $this->request->get('max_wormhole') == 'on',
            (int) $this->request->get('min_legendaries'),
            (float) $this->request->get('min_inf'),
            (float) $this->request->get('min_res'),
            (float) $this->request->get('min_total'),
            (float) $this->request->get('max_total'),
            $this->request->get('custom_factions') ?? [],
            $customSlices,
            $allianceMode,
            $allianceMode ? AllianceTeamMode::from($this->request->get('alliance_teams')) : null,
            $allianceMode ? AllianceTeamPosition::from($this->request->get('alliance_teams_position')) : null,
            $allianceMode ? $this->request->get('force_double_picks') == 'on' : null,
        );
    }

    protected function tileSetsFromRequest()
    {
        $sets = [Edition::BASE_GAME];
        foreach($this->request->get('tileSets', []) as $key => $value) {
            $edition = Edition::from($key);
            if ($value == 'on' && ! in_array($edition, $sets)) {
                $sets[] = $edition;
            }
        }

        return $sets;
    }

    protected function factionSetsFromRequest()
    {
        $sets = [];
        foreach($this->request->get('factionSets', []) as $key => $value) {
            if ($value == 'on') {
                $sets[] = Edition::from($key);
            }
        }

        return $sets;
    }

    /** used for tests */
    public function settingValue($field) {
        return $this->settings->$field;
    }

    /** used for tests */
    public function frankenSettingValue(string $field)
    {
        return $this->frankenSettingsFromRequest()->$field;
    }
}
<?php
    $prefillNames =  get('debug', false) == true;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>TI4 - Milty Draft</title>
    <link rel="stylesheet" href="<?= asset_url('css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:ital,wght@0,300;0,600;1,300&family=Staatliches&display=swap" rel="stylesheet">

    <meta property="og:image" content="<?= asset_url('og.png') ?>" />

    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#1a2266">
    <meta name="msapplication-TileColor" content="#fdfcf8">
    <meta name="theme-color" content="#ffffff">
</head>

<body>
<div class="container">
    <div class="content-wrap">
        <h1>Milty Draft Generator</h1>
        <h2>for Twilight Imperium 4th edition</h2>
    </div>

    <div id="tabs">
        <nav>
            <div class="content-wrap">
                <div class="left">
                    <a class="active" href="#generator">Generator</a>
                </div>
                <div class="right">
                    <a href="#faq">FAQ</a>
                </div>
            </div>
        </nav>

        <div class="tab active" id="generator">
            <form id="generate-form" action="generate.php" method="post">

                <section class="section" id="mode-switch">
                    <div class="content-wrap">
                        <div class="header">
                            <h3>Draft Mode</h3>
                            <p class="help">
                                <strong>Milty</strong> is the classic draft: slices, factions and seats are generated for you and picked in turn.<br />
                                <strong>Franken</strong> shreds every faction into its parts, deals those parts into a bag per player, and lets everyone assemble a faction of their own.
                            </p>
                        </div>
                        <div class="content">
                            <input type="hidden" name="mode" id="mode" value="milty" />
                            <div class="mode-switch">
                                <button type="button" class="mode-option active" data-mode="milty" aria-pressed="true">
                                    Milty Draft
                                    <span>Draft a slice, a faction and a seat.</span>
                                </button>
                                <button type="button" class="mode-option" data-mode="franken" aria-pressed="false">
                                    Franken Draft
                                    <span>Draft faction parts out of a bag and build your own faction.</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
                <div class="section">
                    <div class="content-wrap">
                        <div class="header">
                            <div>
                                <h3>Players</h3>
                                <button class="milty-only" id="enable_alliance_mode" type="button">
                                    Switch to Alliance Mode
                                </button>

                                <button class="alliance_only milty-only" id="disable_alliance_mode" type="button">
                                    Switch to Regular Mode
                                </button>
                            </div>
                            <p class="help">
                                Choose the number of players and fill in their names. Draft order will be randomised unless otherwise specified (in the advanced settings below).
                            </p>


                        </div>
                        <div class="content">

                            <div class="input">
                                <label for="num_players">
                                    Number of players
                                </label>
                                <input type="number" name="num_players" id="num_players" value="6" min="3" max="8" required />

                                <p class="error" id="even_player_number_error">
                                    You must use an equal number of players in alliance mode
                                </p>
                            </div>

                            <div class="players_inputs">
                                <div class="alliance_team team_a">
                                    <p class="team_label">Team 1</p>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Amy") ?>" />
                                    </div>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Ben") ?>" />
                                    </div>
                                </div>
                                <div class="alliance_team team_b">
                                    <p class="team_label">Team 2</p>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Charlie") ?>" />
                                    </div>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Desmond") ?>" />
                                    </div>
                                </div>
                                <div class="alliance_team team_c">
                                    <p class="team_label">Team 3</p>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Esther") ?>" />
                                    </div>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" value="<?php e($prefillNames, "Frank") ?>" />
                                    </div>
                                </div>
                                <div class="alliance_team team_d">
                                    <p class="team_label">Team 4</p>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" />
                                    </div>
                                    <div class="input player">
                                        <input type="text" placeholder="Player Name" name="player[]" />
                                    </div>
                                </div>
                            </div>

                            <a class="btn small" href="#" id="add-player" title="Add Player">+</a>
                        </div>
                    </div>


                </div>

                <div class="section" id="shared-settings">
                    <div class="content-wrap">
                        <div class="header">
                            <h3>Game</h3>
                            <p class="help">
                                These settings are used by both draft modes.
                            </p>
                        </div>
                        <div class="content">
                            <div class="input">
                                <label for="game_name">
                                    Game Name
                                </label>
                                <input type="text" placeholder="Game Name" maxlength="100" name="game_name" id="game_name" />

                                <span class="help">
                                    Optional. To help you remember which draft is which, because after two or three drafts that gets confusing. If you leave this blank it will generate something random like "Operation Glorious Drama".
                                </span>
                            </div>

                            <h4>Draft Order</h4>
                            <div class="input">
                                <label for="preset_draft_order" class="check">
                                    <input type="checkbox" name="preset_draft_order" id="preset_draft_order" /> Use specified player order (don't randomise)
                                </label>
                            </div>

                            <h4>Seed</h4>
                            <div class="input">
                                <label for="seed">
                                    Random Seed (optional)
                                </label>

                                <input type="number" id="seed" name="seed" placeholder="Leave empty for random" />
                                <span class="help">
                                    Set an explicit seed for reproducible draft generation. If left empty, a random seed will be automatically generated. The seed will be displayed after generation, allowing any draft to be reproduced exactly.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="mode-section" id="mode-milty" data-mode="milty">

                <div class="section">
                    <div class="content-wrap">
                        <div class="header">
                            <h3>Alliance Game Variant</h3>
                            <p class="help">
                                The "Alliance" Variant is an Optional play mode that was introduced in Codex II (Affinity)<br />
                                <a target="_blank" href="https://twilight-imperium.fandom.com/wiki/Alliance_Game_Variant">Learn more</a>
                            </p>
                        </div>
                        <div class="content">
                            <label for="alliance_toggle" class="check">
                                <input type="checkbox" name="alliance_on" id="alliance_toggle" value="1" /> Enabled
                            </label>
                            <div class="alliance_only alliance_settings">
                                <h4>Team creation</h4>
                                <label class="check">
                                    <input type="radio" name="alliance_teams" value="preset" checked />
                                    Preset teams
                                </label>
                                <label class="check">
                                    <input type="radio" name="alliance_teams" value="random" />
                                    Random teams
                                </label>
                                <br>
                                <h4>Force teammates position</h4>
                                <label class="check">
                                    <input type="radio" name="alliance_teams_position" value="none" checked />
                                    None
                                </label>
                                <label class="check">
                                    <input type="radio" name="alliance_teams_position" value="neighbors" />
                                    Neighbors
                                </label>
                                <label class="check">
                                    <input type="radio" name="alliance_teams_position" value="opposites" />
                                    Opposites
                                </label>
                                <br>
                                <h4>Force team double picks</h4>
                                <label class="check">
                                    <input type="radio" name="force_double_picks" value="false" checked />
                                    No
                                </label>
                                <label class="check">
                                    <input type="radio" name="force_double_picks" value="true" />
                                    Yes
                                </label>
                                <p class="help">Choose yes if you want both teammates to choose within the same category at once.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section">

                    <div class="content-wrap">
                        <div class="header">
                            <h3>Settings</h3>
                        </div>
                        <div class="content">
                            <div class="input">
                                <label for="num_slices">
                                    Number of Slices
                                </label>

                                <input type="number" id="num_slices" name="num_slices" value="7" max="15" />
                                <span class="help">
                                        Note: The slices are random and not necessarily balanced (more on that below), so increasing this number makes it more relaxed for players to choose.<br />
                                        Number of players + 1 is generally recommended. Maximum number of slices possible is 15 with PoK, TE, DS, DS+ selected.
                                    </span>
                            </div>

                            <div class="input">
                                <label for="num_slices">
                                    Number of Factions
                                </label>

                                <input type="number" id="num_factions" name="num_factions" value="9" />
                                <span class="help">
                                        Note: Less options means more competitive drafting.<br />
                                        Number of players + 3 is kind of recommended, but this is all personal preference.
                                    </span>
                            </div>


                            <h4>Expansions to use:</h4>
                            <span class="help">
                                You can seperately choose which expansions to use for tiles and factions later.
                            </span>
                            <div class="input">
                                <label for="expansion_pok" class="check">
                                    <input type="checkbox" data-toggle-expansion="PoK" id="expansion_pok" checked class="auto-enable" />Prophecy Of Kings
                                </label>
                                <label for="expansion_te" class="check">
                                    <input type="checkbox" data-toggle-expansion="TE" id="expansion_te" class="auto-enable" />Thunder's Edge
                                </label>
                                <label for="expansion_ds" class="check">
                                    <input type="checkbox" data-toggle-expansion="DS" id="expansion_ds" class="auto-enable" />Discordant Stars
                                </label>
                                <label for="expansion_dsplus" class="check">
                                    <input type="checkbox" data-toggle-expansion="DSPlus" id="expansion_dsplus" class="auto-enable" />Discordant Stars Plus
                                </label>
                                <span class="help">
                                    <a target="_blank" href="https://www.reddit.com/r/twilightimperium/comments/pvbbie/discordant_stars_24_homebrew_factions/">Discordant Stars</a> and Discordant Stars Plus are fan made expansion introduced by members of the Discord community.
                                </span>
                            </div>

                            <h4>Factions:</h4>
                            <span class="help">
                                Which faction sets should be included as possible draft options?
                            </span>
                            <div class="input">
                                <label for="factions_base" class="check">
                                    <input type="checkbox" class="draft-faction auto-enable" name="factionSets[BaseGame]" id="factions_base" checked /> Base Game Factions
                                </label>
                                <label for="factions_pok" class="check" data-expansion="PoK">
                                    <input type="checkbox" class="draft-faction auto-enable" name="factionSets[PoK]" id="factions_pok" checked /> Prophecy Of Kings
                                </label>
                                <label for="factions_keleres" class="check" data-expansion="PoK">
                                    <input type="checkbox" name="include_keleres" class="draft-faction" data-num="1" data-set="keleres" id="factions_keleres" /> Include The Council Keleres
                                </label>
                                <span class="help">
                                    The Council Keleres was introduced in <a target="_blank" href="https://images-cdn.fantasyflightgames.com/filer_public/35/e1/35e10f37-4b6d-4479-a117-4e2c571ddfa7/ti_codex_volume_3_vigil_v2_1-compressed.pdf">Codex III</a> and is included in Thunder's Edge.
                                </span>
                                <label for="factions_te" class="check" data-expansion="TE">
                                    <input type="checkbox" class="draft-faction auto-enable" name="factionSets[TE]" id="factions_te" /> Thunder's Edge
                                </label>
                                <label for="factions_ds" class="check" data-expansion="DS">
                                    <input type="checkbox" class="draft-faction auto-enable" name="factionSets[DS]" id="factions_ds" /> Discordant Stars
                                </label>
                                <label for="factions_dsplus" class="check" data-expansion="DSPlus">
                                    <input type="checkbox" class="draft-faction auto-enable" name="factionSets[DSPlus]" id="factions_dsplus" /> Discordant Stars Plus
                                </label>
                            </div>

                            <h4>Tiles:</h4>
                            <span class="help">
                                Which tile sets should be included to generate slices?
                            </span>
                            <div class="input">
                                <label for="tiles_base" class="check">
                                    <input type="checkbox" class="draft-tiles auto-enable" name="tileSets[BaseGame]" id="tiles_base" checked disabled /> Base Game Tiles
                                </label>
                                <label for="tiles_pok" class="check" data-expansion="PoK">
                                    <input type="checkbox" class="draft-tiles auto-enable" name="tileSets[PoK]" id="tiles_pok" checked /> Prophecy Of Kings
                                </label>
                                <label for="tiles_te" class="check" data-expansion="TE">
                                    <input type="checkbox" class="draft-tiles auto-enable" name="tileSets[TE]" id="tiles_te" /> Thunder's Edge
                                </label>
                                <label for="tiles_dsplus" class="check" data-expansion="DSPlus">
                                    <input type="checkbox" class="draft-tiles auto-enable" name="tileSets[DSPlus]" id="tiles_dsplus" /> Discordant Stars Plus
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section">
                    <div class="content-wrap">
                        <div class="header">
                            <h3>Slice Generation</h3>
                        </div>
                        <div class="content">
                            <h4>Wormholes</h4>
                            <div class="input">
                                <label for="wormholes" class="check">
                                    <input type="checkbox" name="wormholes" id="wormholes" /> Include at least 2 alpha and beta wormholes
                                </label>
                                <span class="help">So at least 4 in total, divided over the slices. A slice will never have two of the same wormholes.</span>
                            </div>
                            <div class="input">
                                <label for="max_wormhole" class="check">
                                    <input type="checkbox" name="max_wormhole" id="max_wormhole" /> Max. 1 wormhole per slice
                                </label>
                            </div>
                            <h4>Legendaries</h4>
                            <div class="input">
                                <label for="min_legendaries">
                                    Minimum amount of legendary planets
                                </label>
                                <input type="text" value="0" placeholder="Minimum amount of legendary planets" max="7" name="min_legendaries" id="min_legendaries" />
                                <span class="help">PoK and TE include <a target="_blank" href="https://twilight-imperium.fandom.com/wiki/Planets_and_Systems#Legendary_Planets">2 and 5 draftable legendary planets, respectively</a>. <br /> Discordant Stars has <a href="https://twilight-imperium.fandom.com/wiki/Uncharted_Space_Expansion_(UNOFFICIAL)#Legendary_Planets" target="_blank">5 more</a></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <div class="content-wrap">

                        <div class="header">
                            <div>
                                <h3>Advanced Settings</h3>
                                <a href="#" class="btn" id="more">Show</a>
                            </div>
                            <span class="help">Take a peek under the hood.</span>
                        </div>


                        <div class="content" id="advanced">

                            <h4>Slice generation</h4>
                            <p>
                                The “Optimal Value” of a planet is calculated by using the higher of its resource value and influence value as that value, and the other value as zero.
                                If both of the planet’s resource value and influence value are equal, half that value is used for both of its optimal values.
                                For example, Starpoint, a 3/1, is treated as 3/0, Corneeq, a 1/2, is treated as 0/2, and Rigel III, a 1/1, is treated as ½/½.
                            </p>

                            <div class="input">
                                <label for="min_inf">
                                    Minimum Optimal Influence
                                </label>

                                <input type="number" id="min_inf" step="0.5" min="0" name="min_inf" value="4" />
                            </div>
                            <div class="input">
                                <label for="min_res">
                                    Minimum Optimal Resources
                                </label>

                                <input type="number" id="min_res" required min="0" name="min_res" step="0.5" value="2.5" />
                            </div>
                            <div class="input">
                                <label for="min_total">
                                    Minimum Optimal Total
                                </label>

                                <input type="number" id="min_total" required min="0" name="min_total" step="0.5" value="9" />
                            </div>

                            <div class="input">
                                <label for="max_total">
                                    Maximum Optimal Total
                                </label>

                                <input type="number" id="max_total" required min="0" name="max_total" step="0.5" value="13" />
                            </div>

                            <div class="input">
                                <label for="custom_slices">
                                    Custom Slices
                                </label>

                                <textarea rows="10" name="custom_slices" id="custom_slices" placeholder="1, 2, 3, 4, 5<?= "\n" ?>6, 7, 8, 9, 10<?= "\n" ?>..."></textarea>

                                <span class="help">
                                        You can skip the slice-generation stuff by inputting your own slices. You can do this by listing the tiles in each slice, one per line, seperated by commas.<br />
                                        Note: The order within each line matters! Slices are laid out like this:<br /><br />
                                        <img class="slice-help" src="<?= url('img/slice-layout.png') ?>" /><br /> so the first slice listed will be to positioned top left of the home system, the second one top, third top right,...
                                    </span>
                            </div>

                            <h4>Custom Factions</h4>
                            <div class="input">
                                <label>

                                        <span class="help">
                                            You can pre-select the factions that will be considered in the draft.<br /><br />
                                            If you don't check enough factions to fill up the draft (based on the number of factions above), the generator will add some random (unchecked) ones. <br />
                                            If you check more than enough (e.g: checking 10 factions when the draft only needs 8), a random selection will be made from the checked factions. <br /><br />
                                            This means that if you want to <strong>exclude</strong> certain factions from the draft, you need to check everything except the ones you wish to exclude, and we'll do the rest.<br /><br />
                                            <strong>Note: You can change the selectable factions by checking or unchecking the boxes up where it says "Draftable Factions".</strong>
                                        </span>

                                    <br />
                                    <span>
                                            <a href="#" id="select-all">Select All</a> / <a href="#" id="deselect-all">Deselect All</a>
                                        </span>
                                </label>

                                <div class="input-group factions">
                                    <?php require_once 'templates/factions.php'; ?>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                </section>

                <section class="mode-section inactive" id="mode-franken" data-mode="franken">
<?php
    $frankenSources = json_decode(file_get_contents('data/franken/sources.json'), true) ?: [];

    $frankenCanals = ['official' => 'Official', 'community' => 'Community'];
    $frankenDefaultSources = ['base', 'pok', 'codex1', 'codex2', 'codex3', 'codex4'];

    $frankenGroupedSources = ['official' => [], 'community' => []];
    foreach ($frankenSources as $frankenSource) {
        if (empty($frankenSource['contributes'])) continue;

        $frankenCanal = $frankenSource['canal'] ?? 'community';
        if (! isset($frankenGroupedSources[$frankenCanal])) continue;

        $frankenGroupedSources[$frankenCanal][] = $frankenSource;
    }
    foreach ($frankenGroupedSources as $frankenCanal => $frankenCanalSources) {
        usort($frankenCanalSources, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $frankenGroupedSources[$frankenCanal] = $frankenCanalSources;
    }
?>
                    <div class="section">
                        <div class="content-wrap">
                            <div class="header">
                                <h3>Content Sources</h3>
                                <p class="help">
                                    Every enabled source throws its faction components into the pools the bags are dealt from.
                                    More sources means more variety and more surprises; the official sources alone are plenty for a first Franken game.
                                </p>
                            </div>
                            <div class="content">
<?php foreach ($frankenCanals as $frankenCanal => $frankenCanalLabel): ?>
                                <h4><?= $frankenCanalLabel ?></h4>
                                <span class="help">
                                    <a href="#" class="select-sources" data-canal="<?= $frankenCanal ?>">Select All</a> /
                                    <a href="#" class="deselect-sources" data-canal="<?= $frankenCanal ?>">Select None</a>
                                </span>
                                <div class="input input-group sources">
<?php foreach ($frankenGroupedSources[$frankenCanal] as $frankenSource):
    $frankenSourceId = 'franken_source_' . preg_replace('/[^A-Za-z0-9_]/', '_', $frankenSource['source']);
    $frankenSourceName = trim(preg_replace('/\s+/', ' ', (string) $frankenSource['name'])); ?>
                                    <label class="check" for="<?= $frankenSourceId ?>">
                                        <input type="checkbox" class="franken-source" data-canal="<?= $frankenCanal ?>" name="sources[]" value="<?= htmlspecialchars($frankenSource['source'], ENT_QUOTES) ?>" id="<?= $frankenSourceId ?>" <?php e(in_array($frankenSource['source'], $frankenDefaultSources, true), 'checked') ?> />
                                        <?= htmlspecialchars($frankenSourceName, ENT_QUOTES) ?>
                                        <span class="source-count">(<?= (int) ($frankenSource['components'] ?? 0) ?> items)</span>
                                    </label>
<?php endforeach; ?>
                                </div>
<?php endforeach; ?>
                                <p class="error" id="franken_no_sources_error">
                                    Pick at least one content source.
                                </p>
                                <span class="help">
                                    Home systems and map tiles are limited to the tiles this site already ships, so a community faction may contribute its cards without contributing its home system.
                                    Breakthroughs are only drafted when a Thunder's Edge era source is enabled.
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="content-wrap">
                            <div class="header">
                                <h3>Draft Order</h3>
                                <p class="help">
                                    How is the seating order decided?
                                </p>
                            </div>
                            <div class="content">
                                <div class="input">
                                    <label for="draft_order_mode_drafted" class="check">
                                        <input type="radio" name="draft_order_mode" id="draft_order_mode_drafted" value="drafted" checked /> Drafted
                                    </label>
                                    <span class="help">
                                        Seating order is one of the drafted categories. Every bag holds exactly one seat, so there are exactly as many seats as players and everyone spends picks on where they sit.
                                    </span>
                                </div>
                                <div class="input">
                                    <label for="draft_order_mode_preset" class="check">
                                        <input type="radio" name="draft_order_mode" id="draft_order_mode_preset" value="preset" /> Preset
                                    </label>
                                    <span class="help">
                                        Seating order is fixed when the draft is generated and seats are left out of the bags entirely.
                                        Tick "Use specified player order" under <em>Game</em> above to keep the order you typed; otherwise it is randomised.
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="content-wrap">
                            <div class="header">
                                <h3>Slice Assembly</h3>
                                <p class="help">
                                    How does a player turn their five drafted map tiles into an arranged slice?
                                </p>
                            </div>
                            <div class="content">
                                <div class="input">
                                    <label for="slice_assembly_mode_draft" class="check">
                                        <input type="radio" name="slice_assembly_mode" id="slice_assembly_mode_draft" value="draft" checked /> Tile draft
                                    </label>
                                    <span class="help">
                                        The five tiles are drawn one at a time from a shuffled bag. The tile closest to the galactic center is forced into slot 1 with no choice. Then a middle-ring tile is drawn: the player chooses slot 2 or slot 3 for it without knowing which tile will fill the other one, which is drawn next and has nowhere else to go. The same happens for the two tiles next to home, into slots 4/5.
                                    </span>
                                </div>
                                <div class="input">
                                    <label for="slice_assembly_mode_free" class="check">
                                        <input type="radio" name="slice_assembly_mode" id="slice_assembly_mode_free" value="free" /> Free build
                                    </label>
                                    <span class="help">
                                        The classic click-and-place builder: any of the five drafted tiles may go in any of the five slots.
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="content-wrap">
                            <div class="header">
                                <div>
                                    <h3>Advanced Settings</h3>
                                    <a href="#" class="btn" id="franken-more">Show</a>
                                </div>
                                <span class="help">Customize the bag composition and how many picks each round grants. The defaults match the standard Franken variant.</span>
                            </div>
                            <div class="content" id="franken-advanced">
                                <h4>Picks per round</h4>
                                <span class="help">
                                    How many items a player may take from the bag in front of them before they have to pass it on.
                                </span>
                                <div class="input">
                                    <label for="picks_first_round">Round 1</label>
                                    <input type="number" min="1" id="picks_first_round" name="picks_first_round" value="<?= \App\Franken\FrankenSettings::DEFAULT_PICKS_FIRST_ROUND ?>" />
                                </div>
                                <div class="input">
                                    <label for="picks_per_round">Every round after</label>
                                    <input type="number" min="1" id="picks_per_round" name="picks_per_round" value="<?= \App\Franken\FrankenSettings::DEFAULT_PICKS_PER_ROUND ?>" />
                                </div>

                                <h4>Bag composition</h4>
                                <span class="help">
                                    Each player is dealt one bag holding this many items per category, and may keep at most the "max in hand" amount of each.
                                    Items are dealt without replacement, so no component ever shows up in two bags; if the enabled sources cannot fill a bag at these numbers, generation fails and tells you which category came up short.
                                </span>
                                <table class="franken-limits" id="franken-limits-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>In bag</th>
                                            <th>Max in hand</th>
                                        </tr>
                                    </thead>
                                    <tbody>
<?php foreach (\App\Franken\DraftCategory::displayOrder() as $frankenCategory): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($frankenCategory->label(), ENT_QUOTES) ?> <span class="category-code"><?= $frankenCategory->value ?></span></td>
                                            <td><input type="number" min="0" class="franken-limit-input" data-column="bag" name="limits[<?= $frankenCategory->value ?>][bag]" value="<?= $frankenCategory->defaultBagCount() ?>" /></td>
                                            <td><input type="number" min="0" class="franken-limit-input" data-column="hand" name="limits[<?= $frankenCategory->value ?>][hand]" value="<?= $frankenCategory->defaultHandLimit() ?>" /></td>
                                        </tr>
<?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td>Total</td>
                                            <td id="franken-bag-total"></td>
                                            <td id="franken-hand-total"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <span class="help">
                                    The totals below add up every row shown, whether or not that category ends up active for this draft (draft order when seating is preset, or breakthroughs without Thunder's Edge content, are dropped at generation time regardless of what is entered here).
                                </span>
                                <p>
                                    <a href="#" class="btn" id="franken-limits-reset">Reset to standard Franken</a>
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="input center content-wrap">
                    <p id="error">
                    </p>

                    <p>
                        <button type="submit" id="submit">Generate</button>
                    </p>
                </div>
            </form>
        </div>

        <?php require_once 'templates/faq.php'; ?>
    </div>
</div>

<div class="overlay" id="loading">
    Loading. Please wait.<br />
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
        <circle cx="50" cy="50" r="0" fill="none" stroke="#fefcf8" stroke-width="2">
            <animate attributeName="r" repeatCount="indefinite" dur="1s" values="0;40" keyTimes="0;1" keySplines="0 0.2 0.8 1" calcMode="spline" begin="0s"></animate>
            <animate attributeName="opacity" repeatCount="indefinite" dur="1s" values="1;0" keyTimes="0;1" keySplines="0.2 0 0.8 1" calcMode="spline" begin="0s"></animate>
        </circle>
        <circle cx="50" cy="50" r="0" fill="none" stroke="#fefcf8" stroke-width="2">
            <animate attributeName="r" repeatCount="indefinite" dur="1s" values="0;40" keyTimes="0;1" keySplines="0 0.2 0.8 1" calcMode="spline" begin="-0.5s"></animate>
            <animate attributeName="opacity" repeatCount="indefinite" dur="1s" values="1;0" keyTimes="0;1" keySplines="0.2 0 0.8 1" calcMode="spline" begin="-0.5s"></animate>
        </circle>
    </svg>
</div>

<script>
    window.routes = {
        "generate": "<?= url('api/generate') ?>"
    }
</script>

<script src="<?= asset_url('js/vendor.js') ?>"></script>
<script src="<?= asset_url('js/main.js') ?>"></script>
</body>

</html>

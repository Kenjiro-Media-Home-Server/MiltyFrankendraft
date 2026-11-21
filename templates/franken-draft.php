<?php
    /** @var \App\Franken\FrankenDraft $draft */
    use App\Franken\DraftCategory;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= $draft->settings->name ?> | TI4 - Franken Draft</title>
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
        <h1><?= $draft->settings->name ?></h1>
        <h2>Franken Draft</h2>

        <div id="tabs">
            <nav>
                <div class="content-wrap">
                    <div class="left">
                        <a class="active" href="#draft">Draft</a>
                        <a href="#faction">My Faction</a>
                        <!-- One tab per other player is inserted here at runtime, once everyone has
                             confirmed a faction and the map could be built from it — see
                             renderAllFactions() in js/franken-draft.js. -->
                        <a href="#regen">Regenerate</a>
                        <a href="#map">Map</a>
                        <a href="#log">Log</a>
                        <a href="#config">Config</a>
                        <a href="#session">Session</a>
                    </div>
                    <div class="right">
                        <a href="#faq">FAQ</a>
                    </div>
                </div>
            </nav>

            <div class="tab active" id="draft">
                <div class="content-wrap">
                    <div class="franken-status" id="round-status">
                        <p>
                            <strong id="round-label">Round 1</strong>
                            <span id="picks-label"></span>
                            <span id="waiting-label"></span>
                        </p>
                    </div>
                    <div class="status" id="draft-over">
                        <p>The bag draft is done. <a class="tabnav" href="#faction">Build your faction</a>.</p>
                    </div>
                    <div class="franken-status" id="claim-prompt">
                        <p>Claim your seat below to start drafting.</p>
                    </div>

                    <div class="players franken-players">
                        <?php foreach ($draft->playersInSeatOrder() as $i => $player) : ?>
                            <div id="player-<?= $player->id ?>" class="player franken-player" data-id="<?= $player->id ?>">
                                <h3><span><?= $i + 1 ?></span> <?= htmlspecialchars($player->name, ENT_QUOTES) ?></h3>

                                <span class="you" data-id="<?= $player->id ?>">you</span>
                                <p class="franken-player-state">
                                    <span class="hand-count">0</span>/<span class="hand-total">0</span> drafted<br />
                                    <span class="ready-state">picking&hellip;</span>
                                </p>
                                <p class="center">
                                    <button class="claim" data-id="<?= $player->id ?>">Claim</button>
                                    <button class="unclaim" data-id="<?= $player->id ?>">Unclaim</button>
                                    <button class="force-complete" data-id="<?= $player->id ?>" title="Admin: fill this hand from what is left in circulation">Complete hand</button>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="bag-section">
                        <h3>Your bag <span class="subtle" id="bag-count"></span></h3>
                        <p id="bag-help" class="regen-help">
                            Take what you want, then pass the bag on. Everyone picks at the same time; the
                            bags only move once everybody is done.
                        </p>
                        <p class="center pass-wrap">
                            <button class="pass-bag">Pass this bag on</button>
                            <button class="unpass-bag">I am not done yet</button>
                            <span class="pass-blocked subtle"></span>
                        </p>
                        <div id="round-picks"></div>
                        <div id="bag-items"></div>
                        <p class="center pass-wrap">
                            <button class="pass-bag">Pass this bag on</button>
                            <button class="unpass-bag">I am not done yet</button>
                            <span class="pass-blocked subtle"></span>
                        </p>
                    </div>

                    <hr class="section-divider" />

                    <div id="hand-section">
                        <h3>What you have drafted</h3>
                        <div id="hand-items" class="component-hand"></div>
                    </div>
                </div>
            </div>

            <div class="tab" id="faction">
                <div class="content-wrap">
                    <button class="print-button" onClick="window.print()">Print</button>

                    <div class="franken-status" id="faction-locked">
                        <p>Your faction opens up once the bag draft is finished.</p>
                    </div>

                    <div id="faction-builder">
                        <h3>Your franken faction</h3>
                        <div id="faction-sheet" class="component-hand"></div>

                        <div id="grants-section">
                            <h3>Also granted</h3>
                            <p class="regen-help">Components you receive for free because of what you drafted.</p>
                            <div id="grant-items" class="component-grid"></div>
                        </div>

                        <div id="swaps-section">
                            <h3>Optional exchanges</h3>
                            <p class="regen-help">
                                The Franken rules let a few components be exchanged for something else. Pick
                                whichever version you want to play.
                            </p>
                            <div id="swap-items"></div>
                        </div>

                        <h3>Your slice</h3>
                        <p class="regen-help">
                            Place your three blue and two red tiles into the five slice positions. Your home
                            system sits at the bottom. Slot 1 sits closest to the galactic center, slots 2-3
                            in the middle ring, and slots 4-5 next to your home system.
                        </p>
<?php if ($draft->settings->sliceAssemblyMode === \App\Franken\SliceAssemblyMode::DRAFT): ?>
                        <p class="regen-help">
                            Your five tiles are drawn one at a time from a shuffled bag. Slot 1 is filled
                            automatically first. Then a middle-ring tile is drawn: choose slot 2 or slot 3 for
                            it without knowing which tile will fill the other one — it is drawn next and
                            placed automatically, since only one spot is left for it. The same happens for
                            the two tiles next to home, into slots 4/5. A tile not yet drawn does not appear
                            here at all.
                        </p>
<?php else: ?>
                        <p class="regen-help">
                            Click one of your tiles, then click the spot on the slice it should go in.
                            Clicking a spot that already holds a tile swaps the two.
                        </p>
<?php endif; ?>
                        <div id="slice-builder">
                            <div id="slice-pool">
                                <h4>Your tiles</h4>
                                <div id="slice-tiles" class="tile-pool"></div>
                            </div>
                            <div class="slice-map-wrap">
                                <div class="map-offset">
                                    <div class="map" id="slice-map"></div>
                                </div>
                            </div>
                        </div>
                        <p id="slice-stats"></p>
                        <p class="warning" id="slice-warning"></p>
                        <p class="slice-home-label" id="slice-home-label"></p>

                        <p class="center">
                            <button id="save-faction">Save progress</button>
                            <button id="confirm-faction">Confirm my faction</button>
                        </p>
                        <p class="center" id="faction-confirmed-msg">Your faction is confirmed.</p>
                    </div>
                </div>
            </div>

            <div class="tab" id="regen">
                <div class="content-wrap">
                    <h3>Regenerate</h3>
                    <div id="regen-available">
                        <p class="regen-help">
                            Not happy with the draw? Until the first pick is taken the admin can deal
                            every bag again. Players keep their seats, their claims and their passkeys.
                        </p>
                        <p>
                            <label class="check" for="regen-order">
                                <input type="checkbox" id="regen-order" /> Reshuffle the seating order as well
                            </label>
                        </p>
                        <p>
                            <button id="regenerate" class="btn">Regenerate</button>
                        </p>
                    </div>
                    <div id="regen-unavailable">
                        <p class="regen-help">The draft has started, so the bags can no longer be re-dealt.</p>
                    </div>
                    <div id="regen-not-admin">
                        <p class="regen-help">Only the admin can re-deal the bags.</p>
                    </div>
                </div>
            </div>

            <div class="tab" id="map">
                <div class="content-wrap">
                    <div class="franken-status" id="map-waiting">
                        <p>The map appears once every player has confirmed a slice and a seating position.</p>
                    </div>

                    <div id="map-content">
                        <button class="print-button" onClick="window.print()">Print</button>
                        <p class="map-layout-selector">
                            Select map layout:
                            <select id="change-mapview">
                                <option value="hyperlane">Hyperlanes (default)</option>
                                <option value="slices">Individual Slices</option>
                            </select>
                        </p>

                        <div class="mapview current" id="mapview-hyperlane">
                            <h3 class="map-layout-title">Hyperlane Map</h3>
                            <div class="rotate-map">
                                <button title="Rotate counter-clockwise" class="rotate-map-left">&#10226;</button>
                                <button title="Rotate clockwise" class="rotate-map-right">&#10227;</button>
                            </div>
                            <div class="map-container">
                                <div class="map-offset">
                                    <div id="map-wrap" class="map"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mapview" id="mapview-slices">
                            <h3 class="map-layout-title">Slices</h3>
                            <div class="slice-container">
                                <p>Slices are shown in speaker order</p>
                                <div id="mapslices-wrap"></div>
                            </div>
                        </div>

                        <h3>Tiles</h3>
                        <p id="tile-gather"></p>
                        <h3 class="tts-map-string-title">TTS Map String*</h3>
                        <p id="tts-string"></p>
                        <span class="help">*This feature is in development and hasn't been rigorously tested, so some maps might give odd results.</span>
                    </div>

                    <?php
                        // js/generate-map.js looks the home system up out of the DOM and checks
                        // whether Thunder's Edge tiles are in play, so both are provided here in the
                        // shape it already expects. That keeps the shared renderer untouched.
                        $usesThundersEdgeTiles = in_array('thunders_edge', $draft->settings->sources, true);
                    ?>
                    <span id="include-te" data-te="<?= $usesThundersEdgeTiles ? '1' : '' ?>" hidden></span>
                    <div id="franken-home-systems" hidden></div>
                </div>
            </div>

            <div class="tab" id="log">
                <div class="content-wrap">
                    <h3>Log</h3>
                    <div id="log-content"></div>
                    <br>
                    <button class="undo-last-action">Undo last pick</button>
                    <p class="regen-help" id="undo-help">
                        Admin only. Puts the most recent pick back into the bag it came from, even if
                        that bag has since moved on. Only possible while the bag draft is running.
                    </p>
                </div>
            </div>

            <div class="tab" id="config">
                <div class="content-wrap">
                    <h3>Configuration used</h3>
                    <table class="config-table">
                        <tr>
                            <td>Mode</td>
                            <td>Franken draft (standard)</td>
                        </tr>
                        <tr>
                            <td>Players</td>
                            <td><?= count($draft->players) ?></td>
                        </tr>
                        <tr>
                            <td>Items per bag</td>
                            <td><?= $draft->settings->bagSize() ?></td>
                        </tr>
                        <tr>
                            <td>Items per faction</td>
                            <td><?= $draft->settings->handSize() ?></td>
                        </tr>
                        <tr>
                            <td>Picks per round</td>
                            <td><?= $draft->settings->picksFirstRound ?> in the first round, then <?= $draft->settings->picksPerRound ?></td>
                        </tr>
                        <tr>
                            <td>Seating order</td>
                            <td><?= $draft->settings->draftOrderMode->label() ?></td>
                        </tr>
                        <tr>
                            <td>Slice assembly</td>
                            <td><?= $draft->settings->sliceAssemblyMode->label() ?></td>
                        </tr>
                        <tr>
                            <td>Seed</td>
                            <td><?= $draft->settings->seed->getValue() ?></td>
                        </tr>
                        <tr>
                            <td>Content sources</td>
                            <td><?= htmlspecialchars(implode(', ', $draft->settings->sources), ENT_QUOTES) ?></td>
                        </tr>
                    </table>

                    <h3>Drafted per faction</h3>
                    <table class="config-table">
                        <?php foreach ($draft->settings->categories() as $category) : ?>
                            <tr>
                                <td><?= $category->label() ?></td>
                                <td>
                                    <?= $draft->settings->bagCount($category) ?> per bag,
                                    keep <?= $draft->settings->handLimit($category) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="tab" id="session">
                <div class="content-wrap">
                  <div id="current-session">
                      <h3>Session</h3>
                      <p>Make sure to save your passkey so you can restore your session if it is lost (e.g., cleared cache). The passkey is also useful if you want to draft on another device.</p>
                      <p id="current-session-admin">
                          <label>Admin Passkey:</label><strong></strong>
                      </p>
                      <p id="current-session-player">
                          <label>Passkey:</label><strong></strong>
                      </p>
                      <br>
                  </div>
                  <div>
                      <h3>Restore Session</h3>
                      <form id="secret-form" action="restore.php" method="post">
                          <div class="secret_input_section">
                              <p class="secret_label">Restore a session to be able to draft in this device.</p>
                              <div class="input secret">
                                  <input type="text" placeholder="Passkey" name="secret" />
                              </div>
                          </div>
                          <p>
                              <button type="submit" id="submit">Restore</button>
                          </p>
                      </form>
                  </div>
                </div>
            </div>
            <?php require_once 'templates/faq.php'; ?>
        </div>
    </div>

    <div class="popup" id="peek-popup">
        <div class="content">
            <div id="peek-content" class="component-hand"></div>
            <p>
                <button id="peek-back">&larr; Back</button>
                <button class="close-popup">Close</button>
            </p>
        </div>
    </div>

    <div class="popup" id="confirm-popup">
        <div class="content">
            <p>
                Take <strong><span id="confirm-value"></span></strong> into your faction?<br />
                You can put it back until you pass this bag on.
            </p>
            <p>
                <button id="confirm">Confirm</button>
                <button id="confirm-cancel">Cancel</button>
            </p>
        </div>
    </div>

    <div class="popup" id="error-popup">
        <div class="content">
            <p>Error: <span id="error-message"></span></p>
            <p>
                <button id="close-error">Ok</button>
            </p>
        </div>
    </div>

    <div class="overlay" id="loading">
        Loading. Please wait.<br />
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
            <circle cx="50" cy="50" r="0" fill="none" stroke="#fefcf8" stroke-width="2">
                <animate attributeName="r" repeatCount="indefinite" dur="1s" values="0;40" keyTimes="0;1" keySplines="0 0.2 0.8 1" calcMode="spline" begin="0s"></animate>
                <animate attributeName="opacity" repeatCount="indefinite" dur="1s" values="1;0" keyTimes="0;1" keySplines="0.2 0 0.8 1" calcMode="spline" begin="0s"></animate>
            </circle>
        </svg>
    </div>

    <div class="popup" id="session-popup">
        <div class="content">
            <a class="btn invert close-popup">&times;</a>
            <p id="admin">Your admin passkey is <strong id="popup-admin-passkey">SOME PASSKEY</strong></p>
            <p id="user">Your passkey is <strong id="popup-passkey">SOME PASSKEY</strong></p>
            <p>Write this down somewhere. You can read more about passkeys in the SESSION tab</p>
        </div>
    </div>

    <script>
        // Bootstrapped as a spectator on purpose: the served HTML must never contain a bag, since
        // the page is public. The client re-fetches with its own passkey on load.
        window.draft = <?= json_encode($draft->toArray(null, false)) ?>;
        window.routes = {
            "claim": "<?= url('api/claim') ?>",
            "pick": "<?= url('api/franken/pick') ?>",
            "unpick": "<?= url('api/franken/unpick') ?>",
            "ready": "<?= url('api/franken/ready') ?>",
            "assembly": "<?= url('api/franken/assembly') ?>",
            "tile_images": "<?= url('img/tiles') ?>",
            "data": "<?= url('api/draft/' . $draft->id) ?>",
            "restore": "<?= url('api/restore') ?>",
            "undo": "<?= url('api/undo') ?>",
            "regenerate": "<?= url('api/regenerate') ?>",
            "force_complete": "<?= url('api/franken/force-complete') ?>"
        };
        window.CATEGORY_ORDER = <?= json_encode(array_map(
            static fn (DraftCategory $category) => $category->value,
            DraftCategory::displayOrder(),
        )) ?>;
        window.CATEGORY_LABELS = <?= json_encode(array_reduce(
            DraftCategory::displayOrder(),
            static function (array $labels, DraftCategory $category) {
                $labels[$category->value] = $category->label();

                return $labels;
            },
            [],
        )) ?>;
    </script>
    <script src="<?= asset_url('js/vendor.js') ?>"></script>
    <script src="<?= asset_url('js/franken-draft.js') ?>"></script>
    <script src="<?= asset_url('js/generate-map.js') ?>"></script>
</body>

</html>

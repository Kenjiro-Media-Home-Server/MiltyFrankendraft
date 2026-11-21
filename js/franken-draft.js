/**
 * Franken draft client.
 *
 * Differs from the Milty draft in two ways that shape everything here: all players pick at the
 * same time (so the page polls instead of waiting for a turn), and the bag in front of you is
 * private (so every request carries your passkey and the server decides what you may see).
 */

const POLL_INTERVAL = 5000;

let pollTimer = null;
let pendingPick = null;
let sliceDraft = {};
let selectedTile = null;
/** Set by renderSlice() to whichever player's slice is currently being edited. */
let sliceDraftRings = {};

$(document).ready(function () {
    // Delegated from #tabs, rather than bound directly to "#tabs nav a, .tabnav", so the other-
    // faction tabs added and removed at runtime by renderAllFactions() work without rebinding.
    $('#tabs').on('click', 'nav a, .tabnav', function () {
        let ref = $(this).attr('href');
        $('#tabs nav a, .tab').removeClass('active');
        $('#tabs nav a[href="' + ref + '"]').addClass('active');
        $('.tab' + ref).addClass('active');

        if (ref === '#session') {
            $('#secret-form').off('submit').on('submit', restoreSession);
        }

        if (ref === '#map') {
            renderMap();
        }
    });

    $('.rotate-map-left').on('click', function () {
        rotateMap(-1);
    });

    $('.rotate-map-right').on('click', function () {
        rotateMap(1);
    });

    $('#change-mapview').on('change', function () {
        $('.mapview.current').removeClass('current');
        $('.mapview#mapview-' + $(this).val()).addClass('current');
    });

    $('.close-popup').on('click', function () {
        $(this).parents('.popup').removeClass('open').hide();
    });

    $('#close-error').on('click', function () {
        $('#error-popup').hide();
    });

    $('#confirm-cancel').on('click', function () {
        pendingPick = null;
        $('#confirm-popup').hide();
    });

    $('#confirm').on('click', function () {
        $('#confirm-popup').hide();

        if (pendingPick) {
            post(window.routes.pick, { item: pendingPick, round: window.draft.franken.round });
            pendingPick = null;
        }
    });

    // Bag and hand are re-rendered constantly, so their buttons are bound by delegation.
    $('#bag-items').on('click', 'button.draft', function () {
        let $option = $(this).closest('.franken-option');
        pendingPick = $option.data('id');
        $('#confirm-value').text($option.data('name'));
        $('#confirm-popup').show();
    });

    $('#hand-items').on('click', '.put-back', function (e) {
        e.preventDefault();
        post(window.routes.unpick, { item: $(this).closest('.component-card').data('id') });
    });

    // The same control on a box marked as taken, in the bag itself or in this round's summary.
    $('#bag-items, #round-picks').on('click', '.put-back', function (e) {
        e.preventDefault();
        post(window.routes.unpick, { item: $(this).closest('.franken-option').data('id') });
    });

    $('#bag-items').on('click', '.collapse-toggle', function () {
        toggleCollapsed($(this).data('category'));
        renderBag();
    });

    // Grants and swaps are buttons wherever they are drawn, so this is bound once at the top.
    $(document).on('click', 'button.peek', function (e) {
        e.preventDefault();
        peekAt($(this).data('peek'));
    });

    $('#peek-back').on('click', function (e) {
        e.preventDefault();
        peekBack();
    });

    $('#peek-popup .close-popup').on('click', function () {
        peekStack = [];
    });

    // the same control appears above and below the bag, so nobody has to scroll to pass
    $('.pass-bag').on('click', function () {
        post(window.routes.ready, { ready: 'on', round: window.draft.franken.round });
    });

    $('.unpass-bag').on('click', function () {
        post(window.routes.ready, { ready: 'off', round: window.draft.franken.round });
    });

    $('.franken-players').on('click', '.claim', function () {
        claim($(this).data('id'), false);
    });

    $('.franken-players').on('click', '.unclaim', function () {
        claim($(this).data('id'), true);
    });

    $('#swap-items').on('change', 'input[type=radio]', function () {
        saveFaction(false);
    });

    // Click to place: pick a tile on the left, then click the spot it should go in. Overlapping
    // hex drop zones made dragging fiddly, so this is a plain two-click assignment.
    $('#slice-tiles').on('click', '.tile-option', function () {
        selectTile(String($(this).data('tile')));
    });

    $('#slice-map').on('click', '.slice-slot', function () {
        let position = String($(this).data('position'));
        let occupant = sliceDraft[position] || null;

        if (selectedTile === null) {
            // nothing chosen yet: clicking a filled spot picks that tile up
            if (occupant) selectTile(occupant);

            return;
        }

        if (selectedTile === occupant) {
            // clicking the spot a selected tile already sits in just deselects it
            selectTile(null);

            return;
        }

        if (sliceAssemblyMode() === 'draft' && !tileBelongsAtPosition(selectedTile, position)) {
            $('#slice-warning').text(
                'That tile was drawn for a different ring and cannot go there.'
            );

            return;
        }

        placeTile(position, selectedTile);
    });

    $('.franken-players').on('click', '.force-complete', function () {
        post(window.routes.force_complete, { player: $(this).data('id') });
    });

    $('#regenerate').on('click', function () {
        $.ajax({
            type: 'POST',
            url: window.routes.regenerate,
            dataType: 'json',
            data: {
                id: window.draft.id,
                admin: localStorage.getItem('admin_' + window.draft.id),
                order: $('#regen-order').is(':checked') ? 'true' : 'false',
            },
            success: function (resp) {
                if (resp.error) {
                    showError(resp.error);

                    return;
                }

                window.draft = resp.draft;
                render();
            }
        });
    });

    $('.undo-last-action').on('click', function () {
        post(window.routes.undo, {});
    });

    $('#save-faction').on('click', function () {
        saveFaction(false);
    });

    $('#confirm-faction').on('click', function () {
        saveFaction(true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshData();
            startPolling();
        } else {
            stopPolling();
        }
    });

    if (window.location.hash !== '' && $('.tab' + window.location.hash).length !== 0) {
        $('#tabs nav a[href="' + window.location.hash + '"]').click();
    }

    // The page was served without any bag in it; fetch again as whoever is sitting here.
    refreshData();
    startPolling();
});

// ------------------------------------------------------------------ identity

function credentials() {
    let id = window.draft.id;
    let creds = {};
    let playerId = localStorage.getItem('draft_' + id);
    let secret = localStorage.getItem('secret_' + id);
    let admin = localStorage.getItem('admin_' + id);

    if (playerId && secret) {
        creds.player = playerId;
        creds.secret = secret;
    }

    if (admin) {
        creds.admin = admin;
    }

    return creds;
}

function me() {
    let playerId = localStorage.getItem('draft_' + window.draft.id);

    return playerId ? window.draft.franken.players[playerId] : null;
}

function isAdmin() {
    return !!localStorage.getItem('admin_' + window.draft.id);
}

// -------------------------------------------------------------------- server

function startPolling() {
    stopPolling();
    pollTimer = setInterval(refreshData, POLL_INTERVAL);
}

function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
}

function loading(on) {
    $('body').toggleClass('loading', on !== false);
}

function showError(message) {
    $('#error-message').text(message);
    $('#error-popup').show();
}

function refreshData() {
    $.ajax({
        type: 'GET',
        url: window.routes.data,
        data: credentials(),
        dataType: 'json',
        success: function (resp) {
            if (resp.error) {
                showError(resp.error);

                return;
            }

            window.draft = resp;
            render();
        }
    });
}

function post(url, data) {
    loading(true);

    $.ajax({
        type: 'POST',
        url: url,
        dataType: 'json',
        data: $.extend({ id: window.draft.id }, credentials(), data),
        success: function (resp) {
            loading(false);

            if (resp.error) {
                showError(resp.error);
                refreshData();

                return;
            }

            window.draft = resp.draft;
            render();
        },
        error: function (xhr) {
            loading(false);
            let message = 'Something went wrong.';

            try {
                message = JSON.parse(xhr.responseText).error || message;
            } catch (e) {
                // keep the fallback
            }

            showError(message);
            refreshData();
        }
    });
}

function claim(playerId, unclaim) {
    loading(true);

    $.ajax({
        type: 'POST',
        url: window.routes.claim,
        dataType: 'json',
        data: { draft: window.draft.id, player: playerId, unclaim: unclaim ? 1 : 0 },
        success: function (resp) {
            loading(false);

            if (resp.error) {
                showError(resp.error);

                return;
            }

            if (unclaim) {
                localStorage.removeItem('draft_' + window.draft.id);
                localStorage.removeItem('secret_' + window.draft.id);
            } else {
                localStorage.setItem('draft_' + window.draft.id, resp.player);
                localStorage.setItem('secret_' + window.draft.id, resp.secret);
                $('#popup-passkey').text(resp.secret);
                $('#admin').hide();
                $('#session-popup').show();
            }

            window.draft = resp.draft;
            render();
        }
    });
}

function restoreSession(e) {
    e.preventDefault();
    let $input = $(this).find('input');
    let secret = $input.val();

    if (!secret) return;

    loading(true);

    $.ajax({
        type: 'POST',
        url: window.routes.restore,
        dataType: 'json',
        data: { draft: window.draft.id, secret: secret },
        success: function (resp) {
            loading(false);

            if (resp.error) {
                showError(resp.error);

                return;
            }

            if (resp.admin) {
                localStorage.setItem('admin_' + window.draft.id, resp.admin);
            } else {
                localStorage.setItem('draft_' + window.draft.id, resp.player);
                localStorage.setItem('secret_' + window.draft.id, resp.secret);
            }

            $input.val('');
            refreshData();
        }
    });
}

// ------------------------------------------------------------------ rendering

function render() {
    let f = window.draft.franken;

    renderPlayers();
    renderStatus();

    if (f.stage === 'bag_draft') {
        $('#bag-section, #hand-section').show();
        renderBag();
        renderHand();
        $('#faction-locked').show();
        $('#faction-builder').hide();
    } else {
        $('#bag-section').hide();
        $('#hand-section').show();
        renderHand();
        $('#faction-locked').hide();
        $('#faction-builder').show();
        renderFaction();
    }

    // Runs every render regardless of stage: an admin undo can snap the stage all the way back to
    // bag_draft, and any other-faction tabs from an earlier reveal have to come down with it.
    renderAllFactions();

    renderLog();
    renderRegenerate();
    renderSession();

    if (window.location.hash === '#map') {
        renderMap();
    }

    loading(false);
}

/**
 * Replaces an element's contents only when they differ, so hover and focus survive a poll.
 *
 * Every writer of an element that goes through here must go through here, placeholders included:
 * a direct .html() leaves the cached value describing markup that is no longer on the page, and
 * the next genuine render of that same markup is then skipped as a no-op.
 */
function setHtmlIfChanged($el, html) {
    if ($el.data('rendered') === html) return;

    $el.data('rendered', html);
    $el.html(html);
}

function renderStatus() {
    let f = window.draft.franken;
    let mine = me();

    // .status is the fixed banner that slides in from the top; it is driven by a class, not by
    // jQuery visibility. The persistent blocks are ordinary page content.
    $('#draft-over').toggleClass('show', f.stage !== 'bag_draft');
    $('#round-status').toggle(f.stage === 'bag_draft');
    $('#claim-prompt').toggle(!mine && !isAdmin());

    if (f.stage !== 'bag_draft') return;

    $('#round-label').text('Round ' + f.round);

    if (mine) {
        let left = picksLeftFor(mine);
        let label;

        if (mine.ready) {
            label = ' — you have passed your bag on';
        } else if (left === 0) {
            // the bag stays put until they say so, so spell out what to do next
            label = ' — no picks left, pass the bag on when you are ready';
        } else {
            label = ' — ' + left + ' pick' + (left === 1 ? '' : 's') + ' left';
        }

        $('#picks-label').text(label);
    } else {
        $('#picks-label').text('');
    }

    let waiting = Object.values(f.players)
        .filter(function (p) { return !p.ready; })
        .map(function (p) { return p.name; });

    $('#waiting-label').text(
        waiting.length ? ' — waiting for ' + waiting.join(', ') : ' — everyone is done, passing bags…'
    );
}

/** Quota left this round, capped by how much room is left in the hand. */
/** How much someone holds is public even while what they hold is not. */
function handSize(player) {
    return player.hand_count === undefined ? player.hand.length : player.hand_count;
}

/**
 * Whether anything left in this bag is something this player may still legally hold. Mirrors
 * FrankenDraft::bagHasTakeableItemFor; the server stays the authority, this only drives the UI.
 */
function bagHasTakeableFor(bag, player) {
    let held = {};
    player.hand.forEach(function (id) {
        let category = id.split(':')[0];
        held[category] = (held[category] || 0) + 1;
    });

    return (bag.items || []).some(function (id) {
        let category = id.split(':')[0];

        return (held[category] || 0) < categoryLimit(category);
    });
}

/** The rule: a bag may not be handed on while its holder can still draft from it. */
function mustKeepPicking(bag, player) {
    return picksLeftFor(player) > 0 && bagHasTakeableFor(bag, player);
}

/**
 * Mirrors FrankenDraft::remainingPicksFor; the server stays the authority, this only drives the
 * UI. Capped not just by quota and overall hand room but by what the bag in front of this player
 * can actually still supply: a bag holding only categories they have already filled is worth zero
 * picks however much quota or hand room is technically left.
 */
function picksLeftFor(player) {
    let f = window.draft.franken;
    let cap = Math.max(0, Math.min(f.quota - player.picks_this_round, f.hand_size - handSize(player)));

    let bag = f.bags[f.bag_holders[player.id]];
    if (!bag || !bag.items) return cap;

    let held = {};
    player.hand.forEach(function (id) {
        let category = id.split(':')[0];
        held[category] = (held[category] || 0) + 1;
    });

    let inBag = {};
    bag.items.forEach(function (id) {
        let category = id.split(':')[0];
        inBag[category] = (inBag[category] || 0) + 1;
    });

    let achievable = 0;
    Object.keys(inBag).forEach(function (category) {
        let capacityLeft = Math.max(0, categoryLimit(category) - (held[category] || 0));
        achievable += Math.min(capacityLeft, inBag[category]);
    });

    return Math.min(cap, achievable);
}

function renderPlayers() {
    let f = window.draft.franken;
    let mine = me();

    Object.values(f.players).forEach(function (player) {
        let $el = $('#player-' + player.id);

        $el.find('.hand-count').text(handSize(player));
        $el.find('.hand-total').text(f.hand_size);
        $el.toggleClass('is-ready', player.ready);
        $el.find('.ready-state').text(
            f.stage !== 'bag_draft'
                ? (player.assembly.confirmed ? 'faction confirmed' : 'building faction…')
                : (player.ready ? 'passed the bag on' : 'picking…')
        );

        $el.find('.you').toggle(!!mine && mine.id === player.id);
        $el.find('.claim').toggle(!mine && !player.claimed);
        $el.find('.unclaim').toggle((!!mine && mine.id === player.id) || (isAdmin() && player.claimed));
        $el.find('.force-complete').toggle(
            isAdmin() && f.stage === 'bag_draft' && handSize(player) < f.hand_size
        );
    });
}

function renderBag() {
    let f = window.draft.franken;
    let mine = me();
    let viewer = mine || (isAdmin() ? Object.values(f.players)[0] : null);

    if (!viewer) {
        setHtmlIfChanged($('#bag-items'), '<p class="regen-help">Claim a seat to see your bag.</p>');
        $('#bag-count').text('');
        $('.pass-wrap').hide();

        return;
    }

    let bag = f.bags[f.bag_holders[viewer.id]];

    if (!bag || !bag.items) {
        setHtmlIfChanged($('#bag-items'), '<p class="regen-help">This bag is not visible to you.</p>');
        $('#bag-count').text('');
        $('.pass-wrap').hide();

        return;
    }

    $('#bag-count').text('(' + bag.items.length + ' items left)');

    let takenThisRound = picksThisRound(viewer);
    $('#round-picks').toggle(takenThisRound.length > 0);
    setHtmlIfChanged(
        $('#round-picks'),
        takenThisRound.length ? roundPicksSection(takenThisRound, viewer) : '',
    );
    // Only touch the DOM when it would actually change. The page polls every few seconds, and
    // replacing the nodes under the cursor drops the hover state mid-preview.
    setHtmlIfChanged($('#bag-items'), bagSections(bag.items, viewer, true));

    let canAct = !!mine || isAdmin();
    // You may not hand on a bag you could still draft from, so the control only appears once
    // there is genuinely nothing left for you to take.
    let blocked = mustKeepPicking(bag, viewer);
    let left = picksLeftFor(viewer);

    $('.pass-wrap').toggle(canAct && f.stage === 'bag_draft');
    $('.pass-bag').toggle(canAct && !viewer.ready && !blocked);
    $('.unpass-bag').toggle(canAct && viewer.ready);
    $('.pass-blocked')
        .toggle(canAct && !viewer.ready && blocked)
        .text('Take your remaining ' + left + ' pick' + (left === 1 ? '' : 's') + ' before passing this bag on.');
}

function renderHand() {
    let f = window.draft.franken;
    let mine = me();
    let viewer = mine || (isAdmin() ? Object.values(f.players)[0] : null);

    // Once everyone's faction is confirmed and the map could be built from it (see
    // factionsAreRevealed), every hand is public, so this section becomes "what everyone drafted"
    // for everybody rather than just the caller's own hand.
    if (!viewer || factionsAreRevealed(window.draft)) {
        setHtmlIfChanged($('#hand-items'), allHandsSummary());

        return;
    }

    setHtmlIfChanged($('#hand-items'), handSheet(viewer, f.stage === 'bag_draft'));
}

/** Spectators get a compact read-only view of what everybody has taken. */
/**
 * Somebody else's hand, while the draft is still running. Derived from who is asking rather than
 * from whether the payload happens to be empty, so a player who has drafted nothing yet reads the
 * same way as one who has drafted plenty.
 */
function handIsHidden(player) {
    if (isAdmin() || factionsAreRevealed(window.draft)) return false;

    let mine = me();

    return !mine || mine.id !== player.id;
}

function allHandsSummary() {
    let f = window.draft.franken;
    let html = '';

    Object.values(f.players).forEach(function (player) {
        html += '<div class="hand-block"><h4>' + escapeHtml(player.name) + '</h4>';
        html += handSheet(player, false);
        html += '</div>';
    });

    return html;
}

function handSheet(player, allowPutBack) {
    let f = window.draft.franken;
    let byCategory = {};

    if (handIsHidden(player)) {
        let taken = handSize(player);

        return '<p class="regen-help">' + escapeHtml(player.name) + ' has drafted '
            + taken + ' of ' + f.hand_size
            + (taken === 0 ? '.' : '. What they took stays hidden until the draft is over.')
            + '</p>';
    }

    player.hand.forEach(function (id) {
        let category = id.split(':')[0];
        (byCategory[category] = byCategory[category] || []).push(id);
    });

    let html = '';

    window.CATEGORY_ORDER.forEach(function (category) {
        let limit = categoryLimit(category);

        if (limit === 0) return;

        let held = byCategory[category] || [];
        html += '<div class="hand-category' + (held.length >= limit ? ' complete' : '') + '">';
        html += '<h5>' + escapeHtml(window.CATEGORY_LABELS[category]) + ' <span class="subtle">'
            + held.length + '/' + limit + '</span></h5>';
        html += '<div class="component-grid">';

        held.forEach(function (id) {
            html += card(id, { putBack: allowPutBack && canPutBack(player, id) });
        });

        html += '</div></div>';
    });

    return html;
}

/** What this player took from the bag in front of them this round, newest last. */
function picksThisRound(player) {
    let f = window.draft.franken;

    return f.log.filter(function (entry) {
        return entry.action === 'pick' && entry.round === f.round
            && entry.player === player.id && !!entry.item;
    }).map(function (entry) {
        return entry.item;
    });
}

// ------------------------------------------------------- collapsing categories

function collapsedKey() {
    return 'collapsed_' + window.draft.id;
}

function collapsedCategories() {
    try {
        return JSON.parse(localStorage.getItem(collapsedKey())) || [];
    } catch (e) {
        return [];
    }
}

function isCollapsed(category) {
    return collapsedCategories().indexOf(category) !== -1;
}

function toggleCollapsed(category) {
    let list = collapsedCategories();
    let at = list.indexOf(category);

    if (at === -1) {
        list.push(category);
    } else {
        list.splice(at, 1);
    }

    try {
        localStorage.setItem(collapsedKey(), JSON.stringify(list));
    } catch (e) {
        // A browser that refuses storage just gets no memory of what was collapsed.
    }
}

// ------------------------------------------------------------ peeking at parts

let peekStack = [];

/** Opens a component that another component grants or can be exchanged for. */
function peekAt(id) {
    peekStack.push(id);
    renderPeek();
}

function peekBack() {
    peekStack.pop();

    if (peekStack.length === 0) {
        $('#peek-popup').hide();

        return;
    }

    renderPeek();
}

function renderPeek() {
    $('#peek-content').html(card(peekStack[peekStack.length - 1], {}));
    $('#peek-back').toggle(peekStack.length > 1);
    $('#peek-popup').show();
}

/** The server sends the limits this draft was created with; never reimplement them here. */
function categoryLimit(category) {
    let limits = window.draft.franken.limits || {};

    return limits[category] ? limits[category].hand : 0;
}

function canPutBack(player, id) {
    let f = window.draft.franken;
    let mine = me();

    if (!mine || mine.id !== player.id || player.ready) return false;

    return f.log.some(function (entry) {
        return entry.action === 'pick' && entry.round === f.round
            && entry.player === player.id && entry.item === id;
    });
}

/**
 * What you have taken from the bag in front of you this round, drawn as the bag draws it rather
 * than as a list of names, so the box you picked is the box you see.
 */
function roundPicksSection(ids, viewer) {
    // The round quota is only an upper bound: once item limits close off every category still in
    // the bag, fewer than the quota may actually be achievable, and the message should say so
    // rather than promise a pick the player can no longer make.
    let total = ids.length + picksLeftFor(viewer);
    let html = '<div class="franken-options draft-options"><h3>This round you took'
        + ' <span class="picks-left">' + ids.length + ' of ' + total + '</span></h3>';

    html += '<div class="options">';

    ids.forEach(function (id) {
        html += optionBox(id, { taken: true, putBack: canPutBack(viewer, id) });
    });

    return html + '</div></div>';
}

/**
 * The bag, laid out the way the Milty draft lays out its faction options: one section per
 * category, and inside it a row of equally sized boxes.
 */
function bagSections(ids, viewer, takeable) {
    let byCategory = {};

    ids.forEach(function (id) {
        let category = id.split(':')[0];
        (byCategory[category] = byCategory[category] || []).push(id);
    });

    let held = {};
    viewer.hand.forEach(function (id) {
        let category = id.split(':')[0];
        held[category] = (held[category] || 0) + 1;
    });

    let canPick = takeable && !viewer.ready && picksLeftFor(viewer) > 0 && (!!me() || isAdmin());
    let html = '';

    let takenHere = {};
    picksThisRound(viewer).forEach(function (id) {
        let category = id.split(':')[0];
        (takenHere[category] = takenHere[category] || []).push(id);
    });

    window.CATEGORY_ORDER.forEach(function (category) {
        let items = byCategory[category] || [];
        let takenItems = takenHere[category] || [];

        // A section still belongs on the page when everything left in it is something you took:
        // that is exactly the case where the list would otherwise vanish under you.
        if (!items.length && !takenItems.length) return;

        let limit = categoryLimit(category);
        let remaining = Math.max(0, limit - (held[category] || 0));
        let full = remaining === 0;

        let collapsed = isCollapsed(category);

        html += '<div class="franken-options draft-options' + (collapsed ? ' collapsed' : '') + '">';
        html += '<h3><button type="button" class="collapse-toggle" data-category="'
            + escapeHtml(category) + '" title="Show or hide this category">'
            + (collapsed ? '&#9656;' : '&#9662;') + '</button> '
            + escapeHtml(window.CATEGORY_LABELS[category])
            + ' <span class="picks-left' + (full ? ' complete' : '') + '">'
            + (full
                ? 'all ' + limit + ' picked'
                : remaining + ' of ' + limit + ' still to pick')
            + '</span></h3>';
        html += '<div class="options">';

        // Item 4 from the testers: a pick you made this round stays where it was, marked as
        // taken, so the list keeps its shape and you can put it straight back.
        takenItems.forEach(function (id) {
            html += optionBox(id, { taken: true, putBack: canPutBack(viewer, id) });
        });

        items.forEach(function (id) {
            html += optionBox(id, { draftable: canPick && !full, dimmed: full });
        });

        html += '</div></div>';
    });

    return html;
}

/** One box in a bag section: caption, origin, rules text, wiki link, Draft button. */
function optionBox(id, options) {
    let component = window.draft.franken.components[id];

    if (!component) {
        return '<div class="franken-option option unknown"><div>'
            + '<span class="option-name">' + escapeHtml(id) + '</span></div></div>';
    }

    let classes = ['franken-option', 'option'];
    if (options.dimmed) classes.push('dimmed');
    if (options.taken) classes.push('taken');

    let html = '<div class="' + classes.join(' ') + '" data-id="' + escapeHtml(id)
        + '" data-name="' + escapeHtml(component.name) + '"><div>';

    html += '<span class="option-name">' + escapeHtml(component.name) + '</span>';

    if (component.faction_name) {
        html += '<p class="option-origin">' + escapeHtml(component.faction_name) + '</p>';
    }

    // A tile speaks for itself: show the art, with the same hover zoom the map view uses.
    let hasTileArt = !!(component.meta && component.meta.tile && component.meta.tile_image);

    if (hasTileArt) {
        html += optionTilePreview(component.meta.tile, component.meta.tile_image);
    }

    // The planet listing is the fallback for the handful of tiles with no art in the repo, and
    // the normal body text for everything that is not a tile.
    if (component.text && !hasTileArt) {
        html += '<p class="option-text">' + escapeHtml(component.text).replace(/\n/g, '<br />') + '</p>';
    }

    html += linkedComponents(component, 'option-extra');

    html += '<p class="option-meta">';
    html += '<span class="option-source">' + escapeHtml(component.source) + '</span>';

    html += wikiLink(component);

    html += '</p>';

    if (options.draftable) {
        html += '<button class="draft">Draft</button>';
    }

    if (options.taken) {
        html += '<p class="taken-mark">Taken this round'
            + (options.putBack ? ' <a href="#" class="put-back" title="Put this back">&times;</a>' : '')
            + '</p>';
    }

    return html + '</div></div>';
}

function card(id, options) {
    options = options || {};
    let component = window.draft.franken.components[id];

    if (!component) {
        return '<div class="component-card unknown"><h6>' + escapeHtml(id) + '</h6></div>';
    }

    let classes = ['component-card'];
    if (options.dimmed) classes.push('dimmed');

    let html = '<div class="' + classes.join(' ') + '" data-id="' + escapeHtml(id)
        + '" data-name="' + escapeHtml(component.name) + '">';
    html += '<h6>' + escapeHtml(component.name) + '</h6>';

    if (component.faction_name) {
        html += '<p class="origin">' + escapeHtml(component.faction_name) + '</p>';
    }

    // Same rule as the bag: show the tile itself when there is art, fall back to the listing.
    let hasTileArt = !!(component.meta && component.meta.tile && component.meta.tile_image);

    if (hasTileArt) {
        html += optionTilePreview(component.meta.tile, component.meta.tile_image);
    }

    if (component.text && !hasTileArt) {
        html += '<p class="rules">' + escapeHtml(component.text).replace(/\n/g, '<br />') + '</p>';
    }

    html += linkedComponents(component, 'extra');

    html += '<p class="source">' + escapeHtml(component.source) + wikiLink(component) + '</p>';

    if (options.putBack) {
        html += '<p class="center"><a href="#" class="put-back">Put back</a></p>';
    }

    return html + '</div>';
}

/** The tile picture inside a bag box, with a large preview on hover. */
function optionTilePreview(tile, image) {
    let src = window.routes.tile_images + '/' + escapeHtml(image);

    return '<div class="option-tile">'
        + '<img src="' + src + '" alt="' + escapeHtml(tile) + '" />'
        + '<img class="zoom" src="' + src + '" alt="" />'
        + '</div>';
}

/**
 * What a component drags along with it: the parts it grants for free, and the parts it may be
 * exchanged for. Each one is a button that opens the component itself, so you can read what you
 * would be getting instead of inferring it from a name.
 */
function linkedComponents(component, className) {
    return chipLine(component.grants, 'Also grants', className)
        + chipLine(component.swaps, 'Can be exchanged for', className);
}

function chipLine(ids, label, className) {
    if (!ids || !ids.length) return '';

    return '<p class="' + className + '">' + label + ': '
        + ids.map(function (id) {
            return '<button type="button" class="peek" data-peek="' + escapeHtml(id) + '">'
                + nameOf(id) + '</button>';
        }).join(', ')
        + '</p>';
}

/**
 * There are no per-component wiki pages, so this points at the faction the part came from. Shared
 * by the bag box and the summary card, which used to disagree about whether to show it at all.
 */
function wikiLink(component) {
    if (!component.wiki) return '';

    return ' <a class="more" target="_blank" rel="noopener"'
        + ' title="Wiki page for ' + escapeHtml(component.faction_name || 'this faction') + '"'
        + ' href="' + escapeHtml(component.wiki) + '">[wiki]</a>';
}

function nameOf(id) {
    let component = window.draft.franken.components[id];

    return escapeHtml(component ? component.name : id);
}

// ------------------------------------------------------------------- faction

function renderFaction() {
    let mine = me();
    let f = window.draft.franken;
    let viewer = mine || (isAdmin() ? Object.values(f.players)[0] : null);

    if (!viewer) {
        $('#faction-sheet').html('<p class="regen-help">Claim a seat to build your faction.</p>');

        return;
    }

    $('#faction-sheet').html(handSheet(viewer, false));

    // Grants and swaps both come from the errata, resolved server-side per component.
    let grants = [];
    let swappable = [];

    viewer.hand.forEach(function (id) {
        let component = f.components[id];
        if (!component) return;
        (component.grants || []).forEach(function (granted) {
            if (grants.indexOf(granted) === -1) grants.push(granted);
        });
        if ((component.swaps || []).length) swappable.push(id);
    });

    $('#grants-section').toggle(grants.length > 0);
    $('#grant-items').html(grants.map(function (id) { return card(id, {}); }).join(''));

    $('#swaps-section').toggle(swappable.length > 0);
    $('#swap-items').html(swappable.map(function (id) {
        let component = f.components[id];
        let chosen = viewer.assembly.swaps ? viewer.assembly.swaps[id] : null;
        let html = '<div class="swap-choice"><h5>' + escapeHtml(component.name) + '</h5>';
        html += '<label><input type="radio" name="swap_' + escapeHtml(id) + '" value="" '
            + (chosen ? '' : 'checked') + ' data-from="' + escapeHtml(id) + '" /> Keep '
            + escapeHtml(component.name) + '</label>';

        (component.swaps || []).forEach(function (option) {
            html += '<label><input type="radio" name="swap_' + escapeHtml(id) + '" value="'
                + escapeHtml(option) + '" ' + (chosen === option ? 'checked' : '') + ' data-from="'
                + escapeHtml(id) + '" /> Take ' + nameOf(option) + ' instead</label>';
        });

        return html + '</div>';
    }).join(''));

    renderSlice(viewer);

    let confirmed = viewer.assembly.confirmed;
    $('#save-faction, #confirm-faction').toggle(!confirmed && (!!mine || isAdmin()));
    $('#faction-confirmed-msg').toggle(!!confirmed);
}

/**
 * Once the draft is done, everybody may browse everybody's finished faction — not just their own,
 * which is what the editable builder above already covers. Each other player gets their own tab,
 * read-only, and built without touching the slice-builder's editing state (sliceDraft,
 * selectedTile), which stays scoped to the viewer's own faction.
 *
 * The tabs do not exist in the page at all until the reveal condition is met — not merely hidden —
 * so there is nothing to leak by inspecting the page and nothing to accidentally show through a
 * stray CSS rule. They are created and torn down here rather than templated, since which of them
 * (if any) should exist depends on who is viewing (never a tab for the viewer's own faction).
 */
function renderAllFactions() {
    let f = window.draft.franken;
    let mine = me();

    if (!factionsAreRevealed(window.draft)) {
        removeOtherFactionTabs();

        return;
    }

    let players = Object.values(f.players).sort(function (a, b) { return a.seat - b.seat; });

    players.forEach(function (player) {
        // Already shown on the "My Faction" tab, with the full editable/confirmed layout.
        if (mine && player.id === mine.id) return;

        let $tab = otherFactionTab(player);
        setHtmlIfChanged($tab.find('.other-faction-content'), factionContentHtml(player));
    });
}

/** The tab for this player's faction, creating it (and its nav link) the first time it is needed. */
function otherFactionTab(player) {
    let $tab = $('#faction-' + player.id);

    if ($tab.length) return $tab;

    $(
        '<a href="#faction-' + escapeHtml(player.id) + '" class="other-faction-tab"'
            + ' data-player-id="' + escapeHtml(player.id) + '">' + escapeHtml(player.name) + '</a>'
    ).insertBefore('#tabs nav a[href="#regen"]');

    $tab = $(
        '<div class="tab other-faction-tab" id="faction-' + escapeHtml(player.id) + '"'
            + ' data-player-id="' + escapeHtml(player.id) + '">'
            + '<div class="content-wrap">'
            + '<button class="print-button" onClick="window.print()">Print</button>'
            + '<h3>' + escapeHtml(player.name) + '’s franken faction</h3>'
            + '<div class="other-faction-content"></div>'
            + '</div></div>'
    ).insertBefore('.tab#regen');

    return $tab;
}

/**
 * Called whenever the reveal condition is not (or no longer) met — e.g. before anyone has
 * confirmed, or after an admin undo reopens the assembly stage. Removing the active tab from under
 * the viewer falls back to Draft rather than leaving a blank pane selected.
 */
function removeOtherFactionTabs() {
    if ($('.tab.other-faction-tab.active').length) {
        $('#tabs nav a, .tab').removeClass('active');
        $('#tabs nav a[href="#draft"]').addClass('active');
        $('.tab#draft').addClass('active');
    }

    $('.other-faction-tab').remove();
}

function factionContentHtml(player) {
    let f = window.draft.franken;
    let html = handSheet(player, false);

    let grants = [];

    (player.hand || []).forEach(function (id) {
        let component = f.components[id];
        if (!component) return;
        (component.grants || []).forEach(function (granted) {
            if (grants.indexOf(granted) === -1) grants.push(granted);
        });
    });

    if (grants.length) {
        html += '<h5>Also granted</h5><div class="component-grid">'
            + grants.map(function (id) { return card(id, {}); }).join('') + '</div>';
    }

    let swaps = (player.assembly && player.assembly.swaps) || {};
    let swapEntries = Object.keys(swaps).filter(function (from) { return !!swaps[from]; });

    if (swapEntries.length) {
        html += '<h5>Exchanges made</h5><ul class="swap-summary">' + swapEntries.map(function (from) {
            return '<li>' + nameOf(from) + ' &rarr; ' + nameOf(swaps[from]) + '</li>';
        }).join('') + '</ul>';
    }

    html += staticSliceHtml(player);

    return html;
}

/** A read-only rendering of a confirmed slice, in the same layout as the interactive builder. */
function staticSliceHtml(player) {
    let f = window.draft.franken;
    let slice = (player.assembly && player.assembly.slice) || [];

    if (slice.length !== 5) return '';

    let byTile = {};

    (player.hand || []).forEach(function (id) {
        let category = id.split(':')[0];
        let component = f.components[id];

        if (component && (category === 'BLUETILE' || category === 'REDTILE')) {
            byTile[component.meta.tile] = { image: component.meta.tile_image, name: component.name };
        }
    });

    let home = null;

    (player.hand || []).forEach(function (id) {
        if (id.indexOf('HOMESYSTEM:') !== 0) return;

        let component = f.components[id];

        if (component) home = { tile: component.meta.tile, image: component.meta.tile_image, name: component.name };
    });

    let map = '';

    SLICE_HEXES.forEach(function (hex) {
        let tileId = slice[Number(hex.position)];
        let tile = tileId ? byTile[tileId] : null;

        map += '<div class="tile regular slice-slot' + (tile ? ' filled' : ' empty')
            + '" data-q="' + hex.q + '" data-r="' + hex.r + '"'
            + (tile ? ' title="' + escapeHtml(tile.name) + '"' : '') + '>';
        map += tile ? hexImage(tile.tile, tile.image) : '';
        map += '<span class="slot-number">' + SLICE_DISPLAY_NUMBER[hex.position] + '</span></div>';
    });

    map += '<div class="tile regular slice-home" data-q="' + SLICE_HOME_HEX.q
        + '" data-r="' + SLICE_HOME_HEX.r + '"'
        + (home ? ' title="' + escapeHtml(home.name) + '"' : '') + '>'
        + (home ? hexImage(home.tile, home.image) : '') + '</div>';

    return '<h5>Slice</h5><div class="slice-map-wrap"><div class="map-offset"><div class="map">'
        + map + '</div></div></div>';
}

/**
 * Where each slice position sits on the hex grid, in the axial coordinates js/generate-map.js
 * already uses. Same layout as the Milty slice viewer:
 *
 *       4
 *   3
 *       1
 *   0       2
 *       H
 *
 * The stylesheet turns data-q/data-r into a position, so the geometry stays in one place.
 */
const SLICE_HEXES = [
    { position: '4', q: 0, r: -1 },
    { position: '3', q: -1, r: 0 },
    { position: '1', q: 0, r: 0 },
    { position: '0', q: -1, r: 1 },
    { position: '2', q: 1, r: 0 },
];

const SLICE_HOME_HEX = { q: 0, r: 1 };

/**
 * The friendlier number shown on each slot instead of the raw position index — 1 for the tile
 * closest to Mecatol, 2/3 for the middle ring, 4/5 for the ring next to home. Mirrors
 * App\Franken\SliceLayout::displayNumber; only the label changes, the underlying position keys
 * (and everything stored or shared with the Milty map) stay exactly as they are.
 */
const SLICE_DISPLAY_NUMBER = { '4': 1, '1': 2, '3': 3, '0': 4, '2': 5 };

/** Mirrors App\Franken\SliceLayout::ringOf. */
const SLICE_RING_BY_POSITION = { '4': 1, '1': 2, '3': 2, '0': 3, '2': 3 };

/** The inverse of SLICE_RING_BY_POSITION: which slots belong to a given ring. */
const SLICE_POSITIONS_BY_RING = { 1: ['4'], 2: ['1', '3'], 3: ['0', '2'] };

function sliceAssemblyMode() {
    return (window.draft.config && window.draft.config.slice_assembly_mode) || 'draft';
}

/**
 * The "tile draft" variant draws one tile at a time from viewer.slice_draw_order. A tile that is
 * the only one left for its ring (ring 1's single tile, or the second tile of a ring-2/ring-3 pair
 * once the first has been placed) has nowhere else to go, so it is placed automatically. The first
 * tile of a ring-2/ring-3 pair is a real decision — made without knowing what the second tile of
 * that pair will be — so it is left for the player.
 *
 * Mutates sliceDraft with every auto-placement it makes, then returns the id of the one tile still
 * awaiting a decision, or null once the whole slice is placed. That single tile is the only one
 * ever shown in the pool: the rest are either already on the map or still in the bag.
 */
function advanceSliceDraft(viewer) {
    let order = viewer.slice_draw_order || [];
    let placedTiles = Object.values(sliceDraft);

    for (let i = 0; i < order.length; i++) {
        let tile = order[i];

        if (placedTiles.indexOf(tile) !== -1) continue;

        let ring = i === 0 ? 1 : (i <= 2 ? 2 : 3);
        let emptySlots = SLICE_POSITIONS_BY_RING[ring].filter(function (position) {
            return !sliceDraft[position];
        });

        if (emptySlots.length === 1) {
            // The only tile left for this ring, and the only slot left for it: no decision to make.
            sliceDraft[emptySlots[0]] = tile;
            placedTiles.push(tile);

            continue;
        }

        if (emptySlots.length === 0) continue; // defensive: nothing left to do for this tile

        return tile;
    }

    return null;
}

function renderSlice(viewer) {
    let f = window.draft.franken;
    let draftMode = sliceAssemblyMode() === 'draft';
    let rings = viewer.slice_rings || {};

    sliceDraftRings = rings;

    // Adopt whatever is already stored the first time this renders.
    if (Object.keys(sliceDraft).length === 0 && viewer.assembly.slice && viewer.assembly.slice.length) {
        viewer.assembly.slice.forEach(function (tile, position) {
            sliceDraft[String(position)] = tile;
        });
    }

    // Resolves every tile that has nowhere else to go (ring 1's tile, and the second tile of a
    // ring-2/ring-3 pair once the first is placed), leaving at most one tile — the next real
    // decision — for the pool below.
    let pendingTile = draftMode ? advanceSliceDraft(viewer) : null;

    let tiles = [];
    let home = null;

    viewer.hand.forEach(function (id) {
        let category = id.split(':')[0];
        let component = f.components[id];

        if (!component) return;

        if (category === 'BLUETILE' || category === 'REDTILE') {
            tiles.push({
                id: id,
                tile: component.meta.tile,
                image: component.meta.tile_image,
                name: component.name,
                category: category,
                ring: rings[component.meta.tile] || null,
            });
        }

        if (category === 'HOMESYSTEM') {
            home = { tile: component.meta.tile, image: component.meta.tile_image, name: component.name };
        }
    });

    let byTile = {};
    tiles.forEach(function (tile) {
        byTile[tile.tile] = tile;
    });

    let placed = Object.values(sliceDraft);
    let map = '';

    SLICE_HEXES.forEach(function (hex) {
        let tileId = sliceDraft[hex.position];
        let tile = tileId ? byTile[tileId] : null;
        let ring = SLICE_RING_BY_POSITION[hex.position];

        let chosen = tile && selectedTile === tile.tile;

        map += '<div class="tile regular slice-slot' + (tile ? ' filled' : ' empty')
            + (chosen ? ' selected' : '')
            + '" data-q="' + hex.q + '" data-r="' + hex.r + '"'
            + ' data-position="' + hex.position + '"'
            + ' data-ring="' + ring + '"'
            + (tile ? ' data-tile="' + escapeHtml(tile.tile) + '"'
                + ' title="' + escapeHtml(tile.name) + '"' : '') + '>';

        // No captions on the map: the tile art carries its own name, and labels crowd hexes this
        // small. The name is on the title attribute, and the spot number identifies the slot.
        if (tile) {
            map += hexImage(tile.tile, tile.image);
        }

        map += '<span class="slot-number">' + SLICE_DISPLAY_NUMBER[hex.position] + '</span>';
        map += '</div>';
    });

    // The home system is fixed: it is not one of the five drafted slice tiles.
    map += '<div class="tile regular slice-home" data-q="' + SLICE_HOME_HEX.q
        + '" data-r="' + SLICE_HOME_HEX.r + '"'
        + (home ? ' title="' + escapeHtml(home.name) + '"' : '') + '>';
    map += home ? hexImage(home.tile, home.image) : '';
    map += '</div>';

    $('#slice-map').html(map);

    // In draft mode, only the one tile still awaiting a decision is ever shown: everything before
    // it is already on the map, and everything after it is still in the bag — including the tile
    // that will fill the other slot of the current ring, which the player cannot see yet.
    let visibleTiles = draftMode
        ? tiles.filter(function (tile) { return tile.tile === pendingTile; })
        : tiles;

    $('#slice-tiles').html(visibleTiles.map(function (tile) {
        let used = placed.indexOf(tile.tile) !== -1;
        let ringLabel = draftMode && tile.ring ? '<span class="tile-ring">Ring ' + tile.ring + '</span>' : '';

        // image only: the name is on the tile art already, and a caption here would crowd the column
        return '<div class="tile-option ' + tile.category.toLowerCase() + (used ? ' used' : '')
            + (selectedTile === tile.tile ? ' selected' : '')
            + '" data-tile="' + escapeHtml(tile.tile) + '"'
            + ' data-ring="' + (tile.ring || '') + '"'
            + ' title="' + escapeHtml(tile.name) + '">'
            + tileImage(tile.tile, tile.image) + ringLabel + '</div>';
    }).join(''));

    $('#slice-home-label').text(home ? 'Home system: ' + home.name : '');

    let complete = Object.keys(sliceDraft).length === 5;

    if (complete) {
        $('#slice-stats').text('Slice complete.');
    } else if (draftMode && pendingTile) {
        let ring = rings[pendingTile];
        $('#slice-stats').text(
            'You drew a ring ' + ring + ' tile — place it in either ring ' + ring + ' slot. '
            + 'You will not see the tile that fills the other one until you do.'
        );
    } else {
        $('#slice-stats').text('Place all five tiles to finish your slice.');
    }

    $('#slice-warning').text('');
}

/**
 * A tile thumbnail for the pool. The filename is resolved at sync time (Discordant Stars home
 * systems are filed under their faction, not their tile number) and a couple of tiles have no art
 * in the repo at all.
 */
function tileImage(tile, image) {
    if (!image) {
        return '<span class="tile-noimage">' + escapeHtml(tile) + '</span>';
    }

    return '<img src="' + window.routes.tile_images + '/' + escapeHtml(image) + '" alt="'
        + escapeHtml(tile) + '" />';
}

/** A tile on the hex map: the tile image plus the big hover zoom the map view also has. */
function hexImage(tile, image) {
    if (!image) {
        return '<span class="tile-noimage">' + escapeHtml(tile) + '</span>';
    }

    let src = window.routes.tile_images + '/' + escapeHtml(image);

    return '<img src="' + src + '" alt="' + escapeHtml(tile) + '" />'
        + '<img class="zoom" src="' + src + '" alt="" />';
}

/** Highlights the chosen tile, wherever it currently is. */
function selectTile(tile) {
    selectedTile = selectedTile === tile ? null : tile;
    renderFaction();
}

/**
 * Whether this tile is allowed to sit at this position. Free build has no such restriction; the
 * tile draft variant requires the tile's drawn ring to match the slot's ring — mirrors
 * SubmitAssembly::validateSliceDraftOrder, the server stays the authority.
 */
function tileBelongsAtPosition(tileId, position) {
    if (sliceAssemblyMode() !== 'draft') return true;

    let tileRing = sliceDraftRings[tileId];

    return !tileRing || tileRing === SLICE_RING_BY_POSITION[position];
}

/** Puts a tile into a slot, moving it out of whatever slot it was in and swapping if needed. */
function placeTile(position, tileId) {
    let previous = sliceDraft[position] || null;
    let cameFrom = null;

    Object.keys(sliceDraft).forEach(function (key) {
        if (sliceDraft[key] === tileId) cameFrom = key;
    });

    sliceDraft[position] = tileId;

    if (cameFrom !== null && cameFrom !== position) {
        // dragged from another slot: the two tiles trade places
        if (previous) {
            sliceDraft[cameFrom] = previous;
        } else {
            delete sliceDraft[cameFrom];
        }
    }

    selectedTile = null;
    renderFaction();
}

function clearSlot(position) {
    delete sliceDraft[position];
    renderFaction();
}

function saveFaction(confirm) {
    let slice = [];

    for (let position = 0; position < 5; position++) {
        if (sliceDraft[String(position)]) slice.push(sliceDraft[String(position)]);
    }

    let swaps = {};
    $('#swap-items input[type=radio]:checked').each(function () {
        if ($(this).val()) swaps[$(this).data('from')] = $(this).val();
    });

    post(window.routes.assembly, {
        swaps: swaps,
        slice: slice,
        confirm: confirm ? 'on' : 'off'
    });
}

/** The bags can only be re-dealt by the admin, and only before the first pick. */
function renderRegenerate() {
    let f = window.draft.franken;
    let nothingPickedYet = Object.values(f.players).every(function (p) {
        return p.hand.length === 0;
    });

    $('#regen-not-admin').toggle(!isAdmin());
    $('#regen-available').toggle(isAdmin() && nothingPickedYet);
    $('#regen-unavailable').toggle(isAdmin() && !nothingPickedYet);
}

// ----------------------------------------------------------------- log, session

function renderLog() {
    let f = window.draft.franken;
    let mine = me();
    // The server stops redacting other players' picks the moment everyone has confirmed (see
    // FrankenDraft::handsAreHidden), but the spoiler-free reveal here waits a little longer — until
    // the map itself could be built — so the log keeps masking other players' picks in between.
    let revealed = factionsAreRevealed(window.draft);
    let html = '';

    f.log.slice().reverse().forEach(function (entry) {
        let who = entry.player && f.players[entry.player] ? f.players[entry.player].name : '';
        let isOwnPick = !!mine && entry.player === mine.id;
        let canSeeItem = isAdmin() || revealed || isOwnPick;

        if (entry.action === 'pass_bags') {
            html += '<p><strong>Round ' + entry.round + '</strong> — bags passed on</p>';
        } else if (entry.action === 'pick') {
            html += '<p>Round ' + entry.round + ': ' + escapeHtml(who) + ' drafted '
                + (entry.item && canSeeItem ? nameOf(entry.item) : '<span class="subtle">something</span>') + '</p>';
        } else if (entry.action === 'confirm_faction') {
            html += '<p>' + escapeHtml(who) + ' confirmed their faction</p>';
        } else if (entry.action === 'ready') {
            html += '<p>Round ' + entry.round + ': ' + escapeHtml(who) + ' passed their bag on</p>';
        }
    });

    $('#log-content').html(html || '<p>Nothing drafted yet.</p>');
    $('.undo-last-action, #undo-help').toggle(isAdmin() && f.stage === 'bag_draft');
}

function renderSession() {
    let admin = localStorage.getItem('admin_' + window.draft.id);
    let secret = localStorage.getItem('secret_' + window.draft.id);

    $('#current-session-admin').toggle(!!admin).find('strong').text(admin || '');
    $('#current-session-player').toggle(!!secret).find('strong').text(secret || '');
}

function escapeHtml(value) {
    return $('<div>').text(value === undefined || value === null ? '' : value).html();
}

// ----------------------------------------------------------------------- map

let mapRotation = 0;

function rotateMap(direction) {
    mapRotation = (mapRotation + direction) % 6;
    $('#mapview-hyperlane .map').attr('data-rotate', mapRotation);
}

/**
 * The map is drawn by js/generate-map.js, which is shared with the Milty draft. Rather than fork
 * it, the Franken state is reshaped into the object it already reads and swapped in for the call.
 * generate-map.js reads the global `draft`, so it is put back straight afterwards.
 */
function renderMap() {
    let franken = window.draft;
    let ready = mapIsReady(franken);

    $('#map-waiting').toggle(!ready);
    $('#map-content').toggle(ready);

    if (!ready) return;

    // It also looks each player's home system up out of the DOM, keyed by "faction". The real
    // image filename travels alongside the tile id: community-source home systems (Discordant
    // Stars and later) are filed under their faction name, not a tile number, so generate-map.js
    // cannot reliably guess their filename from the id alone.
    $('#franken-home-systems').html(
        Object.values(franken.franken.players).map(function (player) {
            let home = homeSystemFor(franken, player);

            return '<span data-faction="' + escapeHtml(player.id) + '" data-homesystem="'
                + escapeHtml(home.tile) + '" data-homesystem-image="' + escapeHtml(home.image || '')
                + '"></span>';
        }).join('')
    );

    window.draft = miltyShapeOf(franken);
    window.map_cached = false;

    try {
        generate_map();
    } finally {
        window.draft = franken;
    }
}

/** A map needs a confirmed slice and a seating position for everyone, and at least 3 seats. */
function mapIsReady(franken) {
    let players = Object.values(franken.franken.players);

    if (players.length < 3) {
        $('#map-waiting p').text(
            'The hyperlane map templates only cover 3 to 8 players, so no map is drawn for this draft.'
        );

        return false;
    }

    return everyoneHasAMapReadySlice(players);
}

/** @param {Array} players */
function everyoneHasAMapReadySlice(players) {
    return players.every(function (player) {
        return (player.assembly.slice || []).length === 5 && seatOf(player) !== null;
    });
}

/**
 * Other players' factions and drafted items stay spoiler-free until everyone has confirmed their
 * faction *and* the map itself could actually be built from what they confirmed — matching the
 * moment the Map tab has something to show, not just the moment confirmations are in.
 *
 * Below 3 players no map is ever drawn (see mapIsReady), so confirmation alone is the reveal there
 * — otherwise a 2-player draft would never show anyone anything.
 */
function factionsAreRevealed(franken) {
    if (franken.franken.stage !== 'done') return false;

    let players = Object.values(franken.franken.players);

    if (players.length < 3) return true;

    return everyoneHasAMapReadySlice(players);
}

function seatOf(player) {
    for (let i = 0; i < player.hand.length; i++) {
        if (player.hand[i].indexOf('DRAFTORDER:') === 0) {
            // drafted positions are 1-based; generate-map.js works in 0-based seats
            return parseInt(player.hand[i].split(':')[1], 10) - 1;
        }
    }

    return null;
}

function homeSystemFor(franken, player) {
    for (let i = 0; i < player.hand.length; i++) {
        if (player.hand[i].indexOf('HOMESYSTEM:') === 0) {
            let component = franken.franken.components[player.hand[i]];

            if (component && component.meta) {
                return { tile: component.meta.tile, image: component.meta.tile_image };
            }
        }
    }

    return { tile: '0', image: null };
}

/** Builds the object shape js/generate-map.js expects out of a Franken draft. */
function miltyShapeOf(franken) {
    let players = {};
    let slices = [];

    Object.values(franken.franken.players).forEach(function (player, index) {
        slices.push({ tiles: player.assembly.slice });

        players[player.id] = {
            id: player.id,
            name: player.name,
            position: seatOf(player),
            slice: index,
            // the home system is resolved through the data-faction lookup above
            faction: player.id,
        };
    });

    return {
        id: franken.id,
        config: { players: franken.config.players },
        slices: slices,
        draft: { players: players, log: [], current: null },
    };
}

let advanced_open = false;
let franken_advanced_open = false;
let alliance_mode = false;

// 'milty' or 'franken'. Mirrored into the hidden #mode input that is posted with the form.
let draft_mode = 'milty';
// Alliance mode is Milty-only, so it is switched off while Franken is selected and put back after.
let alliance_mode_before_franken = false;

const EDITIONS = {
    BASEGAME: {
        id: 'BaseGame'
    },
    POK: {
        id: 'PoK'
    },
    TE: {
        id: 'TE'
    },
    DS: {
        id: 'DS'
    },
    DSPLUS: {
        id: 'DSPlus'
    },
};

$(document).ready(function () {
    $('input[data-toggle-expansion]').each((_, el) => {
        toggleExpansion($(el));
    }).on('change', (e) => {
        toggleExpansion($(e.currentTarget))
    });

    $('#tabs nav a').on('click', function (e) {
        e.preventDefault();
        $('#tabs nav a, .tab').removeClass('active');
        $(this).addClass('active');
        $('.tab' + $(this).attr('href')).addClass('active');
    });

    $('#more').on('click', function (e) {
        e.preventDefault();
        advanced_open = !advanced_open
        $('#advanced').slideToggle();
        if (advanced_open) {
            $(this).html('hide');
        } else {
            $(this).html('show');
        }
    });

    $('.mode-option').on('click', function (e) {
        e.preventDefault();
        set_draft_mode($(this).data('mode'));
    });

    $('#franken-more').on('click', function (e) {
        e.preventDefault();
        franken_advanced_open = !franken_advanced_open
        $('#franken-advanced').slideToggle();
        if (franken_advanced_open) {
            $(this).html('hide');
        } else {
            $(this).html('show');
        }
    });

    $('.select-sources').on('click', function (e) {
        e.preventDefault();
        toggle_sources($(this).data('canal'), true);
    });
    $('.deselect-sources').on('click', function (e) {
        e.preventDefault();
        toggle_sources($(this).data('canal'), false);
    });

    $('#franken-limits-table').on('input', '.franken-limit-input', update_franken_limit_totals);
    update_franken_limit_totals();

    $('#franken-limits-reset').on('click', function (e) {
        e.preventDefault();
        $('.franken-limit-input, #picks_first_round, #picks_per_round').each(function () {
            $(this).val($(this).prop('defaultValue'));
        });
        update_franken_limit_totals();
    });


    if (window.location.hash != '' && $('.tab' + window.location.hash).length != 0) {
        $('#tabs nav a[href="' + window.location.hash + '"]').click();
    }

    $('#select-all').on('click', function (e) {
        e.preventDefault();
        $('.custom_faction:not(:disabled)').prop('checked', true);
    });
    $('#deselect-all').on('click', function (e) {
        e.preventDefault();
        $('.custom_faction').prop('checked', false);
    });

    $('#add-player').on('click', function (e) {
        e.preventDefault();
        var step = $("#alliance_toggle").is(':checked') ? 2 : 1;
        $('#num_players').val(parseInt($('#num_players').val()) + step);
        update_player_count();
    })

    $('#generate-form').on('submit', function (e) {
        e.preventDefault();
        clear_errors();

        if (! validate_form()) {
            return;
        }

        loading();

        let formData = new FormData();
        let values = active_form_values();
        for (let i = 0; i < values.length; i++) {
            formData.append(values[i].name, values[i].value);
        }

        // @todo json body
        const request = new XMLHttpRequest();
        request.open("POST", routes.generate);
        request.onreadystatechange = function () {
            if (request.readyState != 4) return;

            let data = JSON.parse(request.responseText);

            if (data.error) {
                $('#error').show().html(data.error);
                loading(false);
            } else if (request.status == 200) {
                localStorage.setItem('admin_' + data.id, data.admin);
                window.location.href = "d/" + data.id + '?fresh=1';
            }
            // alert("Success: " + r.responseText);
        };
        request.send(formData);

    });

    $('#enable_alliance_mode').on('click', function() {
        $('#alliance_toggle').prop('checked', true);
        update_alliance_mode();
    });
    $('#disable_alliance_mode').on('click', function() {
        $('#alliance_toggle').prop('checked', false);
        update_alliance_mode();
    });

    $("#alliance_toggle").on('change', update_alliance_mode);

    $("input[name='alliance_teams']").on('change', update_alliance_teams);

    update_alliance_mode();
    init_player_count();
    set_draft_mode($('#mode').val() || 'milty');
});

function toggleExpansion($checkbox) {
    const expansion = $checkbox.data('toggle-expansion');
    $checkbox.is(':checked') ? enableExpansion(expansion) : disableExpansion(expansion);
}

function enableExpansion(expansion) {
    $('[data-expansion="' + expansion + '"]')
        .prop('disabled', false)
        .removeClass('disabled');
    $('.check[data-expansion="' + expansion + '"] input').each((_, el) => {
        $checkbox = $(el);
        $checkbox.prop('disabled', false)
        $checkbox.prop('checked', $checkbox.hasClass('auto-enable'))
    });

    if (expansion == 'PoK') {
        toggleDiscordantAvailability(true);
    }

    toggleKeleres();
}

function toggleKeleres() {
    const POKIsEnabled = $('[data-toggle-expansion="PoK"]').is(':checked');
    const TEIsEnabled = $('[data-toggle-expansion="TE"]').is(':checked');

    let keleresIsAllowed = false;
    let keleresIsRequired = false;

    if (TEIsEnabled) {
        // keleres is checked and disabled
        $('[data-set="keleres"]')
            .prop('disabled', true)
            .prop('checked', true)
            .parent().addClass('disabled')
    } else {
        if (POKIsEnabled) {
            // keleres is optional and enabled
            $('[data-set="keleres"]')
                .prop('disabled', false)
                .parent().removeClass('disabled')
        } else {
            // keleres is unavailable and disabled
            $('[data-set="keleres"]')
                .prop('disabled', true)
                .prop('checked', false)
                .parent().addClass('disabled')
        }
    }

    if (keleresIsRequired) {
        $('[data-set="keleres"]')
            .prop('disabled', true)
            .prop('checked', true)
            .parent().addClass('disabled')
    }
}

function toggleDiscordantAvailability(pokIsEnabled) {

    if (pokIsEnabled) {
        $('[data-toggle-expansion="DS"]').prop('disabled', false).parent().removeClass('disabled');
        $('[data-toggle-expansion="DSPlus"]').prop('disabled', false).parent().removeClass('disabled');
    } else {
        $('[data-toggle-expansion="DS"]').prop('disabled', true).parent().addClass('disabled');
        $('[data-toggle-expansion="DSPlus"]').prop('disabled', true).parent().addClass('disabled');
        disableExpansion('DS');
        disableExpansion('DSPlus');
    }
}

function disableExpansion(expansion) {
    $('[data-expansion="' + expansion + '"]')
        .prop('disabled', true)
        .addClass('disabled');
    $('.check[data-expansion="' + expansion + '"] input').each((_, el) => {
        $checkbox = $(el);
        $checkbox.prop('disabled', true)
        $checkbox.prop('checked', false)
    });

    if (expansion == 'PoK') {
        toggleDiscordantAvailability(false);
    }

    toggleKeleres();
}

function update_alliance_mode() {
    alliance_mode = $('#alliance_toggle').is(':checked');

    if (alliance_mode) {
        var numPlayers = $("#num_players").val();
        if (numPlayers % 2 == 1) {
            $("#num_players").val(parseInt(numPlayers) + 1).trigger('change');
        }
        $("#num_players").attr("step", 2).attr("min", 4);
        $(".alliance_only input").prop("disabled", false);
        $(".alliance_only").show();
        $(".players_inputs").addClass("alliance_on");
        $('#enable_alliance_mode').hide();
        update_alliance_teams();
        init_player_count();
    }
    else {
        $("#num_players").attr("min", 3).attr("step", 1);
        $(".alliance_only input").prop("disabled", true);
        $(".alliance_only").hide();
        $(".players_inputs").removeClass("alliance_on");
        $('#enable_alliance_mode').show();
    }

}

function update_alliance_teams() {
    $(".players_inputs").toggleClass("alliance_preset_teams", $('input[name="alliance_teams"]:checked').val() == "preset");
}


/**
 * Switch the form between the Milty and the Franken settings.
 *
 * Only the fields of the visible mode section are posted (see active_form_values), so the hidden
 * mode never contributes settings, and the shared fields at the top of the form are posted in both.
 */
function set_draft_mode(mode) {
    draft_mode = mode === 'franken' ? 'franken' : 'milty';

    $('#mode').val(draft_mode);

    $('.mode-option')
        .removeClass('active')
        .attr('aria-pressed', 'false');
    $('.mode-option[data-mode="' + draft_mode + '"]')
        .addClass('active')
        .attr('aria-pressed', 'true');

    $('.mode-section').each(function () {
        $(this).toggleClass('inactive', $(this).data('mode') !== draft_mode);
    });

    $('body')
        .toggleClass('mode-milty', draft_mode === 'milty')
        .toggleClass('mode-franken', draft_mode === 'franken');

    // Alliance mode is a Milty-only variant (spec 2.1), so Franken never shows or posts it.
    if (draft_mode === 'franken') {
        if (alliance_mode) {
            alliance_mode_before_franken = true;
            $('#alliance_toggle').prop('checked', false);
            update_alliance_mode();
        }
    } else if (alliance_mode_before_franken) {
        alliance_mode_before_franken = false;
        $('#alliance_toggle').prop('checked', true);
        update_alliance_mode();
    }

    clear_errors();
}

function toggle_sources(canal, checked) {
    $('.franken-source[data-canal="' + canal + '"]').prop('checked', checked);
}

/** Keeps the "Total" row of the Franken bag/hand table in sync with what has been typed in. */
function update_franken_limit_totals() {
    let totals = { bag: 0, hand: 0 };

    $('.franken-limit-input').each(function () {
        let column = $(this).data('column');
        let value = parseInt($(this).val(), 10);

        totals[column] += isNaN(value) ? 0 : value;
    });

    $('#franken-bag-total').text(totals.bag);
    $('#franken-hand-total').text(totals.hand);
}

/**
 * The fields that belong to the draft we are about to generate: everything outside a mode
 * section (the shared settings) plus the fields of the currently selected mode.
 */
function active_form_values() {
    return $('#generate-form')
        .find('input, select, textarea')
        .filter(function () {
            const section = $(this).closest('.mode-section');

            return section.length === 0 || section.data('mode') === draft_mode;
        })
        .serializeArray();
}

function validate_form() {
    if (draft_mode === 'franken' && $('.franken-source:checked').length === 0) {
        $('#franken_no_sources_error').show();
        show_error('Pick at least one content source for the Franken draft.');

        return false;
    }

    return true;
}

function show_error(message) {
    $('#error').show().html(message);
}

function clear_errors() {
    $('#error').hide();
    $('#franken_no_sources_error').hide();
}

function loading(loading = true) {
    if (loading) {
        $('body').addClass('loading');
    } else {
        $('body').removeClass('loading');
    }
}

function init_player_count() {
    $('#num_players').on('change', update_player_count);
    $('#num_players').on('keyup', update_player_count);
    update_player_count();
}

function update_player_count() {
    $('.player').show();


    let numPlayers = parseInt($('#num_players').val());
    $('#even_player_number_error').hide();

    console.log(alliance_mode);
    if(alliance_mode) {
        // validate
        if(numPlayers % 2 != 0) {
            $('#even_player_number_error').show();
            $('.alliance_team').hide();
        } else {
            $('.alliance_team').show();
            console.log((Math.ceil(numPlayers / 2) - 1));
            $('.alliance_team:gt(' + (Math.ceil(numPlayers / 2) - 1) + ')').hide();
        }
    }

    numPlayers = Math.max(3, Math.min(8, numPlayers));
    $('#num_players').val(numPlayers);
    $('#add-player').toggle(numPlayers < 8);
    $('.player:gt(' + (numPlayers - 1) + ')').hide().find('input').val('');
}

/**
 * Intercom WooCommerce Sync — Admin JavaScript
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

(function ($) {
  "use strict";

  var i18n = (etherlabzIntercomAdmin && etherlabzIntercomAdmin.i18n) ? etherlabzIntercomAdmin.i18n : {};

  $(document).ready(function () {
    /* ---------------------------------------------------------------
	   Tab navigation
	   ---------------------------------------------------------------
	   The active tab must survive (a) settings-form submits, which round-trip
	   through options.php and lose any URL fragment, and (b) plain page
	   reloads. We persist via two channels:
	     - URL hash (#tab-log) so the tab is shareable / bookmarkable
	     - localStorage as a fallback, since form submits drop the hash
	   On load: hash wins, then localStorage, then the server's default.
	   --------------------------------------------------------------- */

    var STORAGE_KEY = "etherlabz_intercom_active_tab";

    function activateTab(tab) {
      var $btn = $(".iws-tabs__tab[data-tab='" + tab + "']");
      var $panel = $("#iws-panel-" + tab);
      // Defensive: ignore unknown tab slugs (e.g. stale localStorage from an
      // older release that had a tab we've since renamed).
      if (!$btn.length || !$panel.length) return false;

      $(".iws-tabs__tab")
        .removeClass("iws-tabs__tab--active")
        .attr("aria-selected", "false");
      $btn.addClass("iws-tabs__tab--active").attr("aria-selected", "true");

      $(".iws-tab-panel").removeClass("iws-tab-panel--active");
      $panel.addClass("iws-tab-panel--active");
      return true;
    }

    function persistTab(tab) {
      try {
        window.localStorage.setItem(STORAGE_KEY, tab);
      } catch (e) {
        /* private mode / quota — non-fatal, hash still works */
      }
      // Update the hash without scrolling or pushing a history entry.
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, "", "#tab-" + tab);
      } else {
        window.location.hash = "tab-" + tab;
      }
    }

    function readPersistedTab() {
      var fromHash = (window.location.hash || "").match(/^#tab-([a-z0-9_-]+)$/i);
      if (fromHash) return fromHash[1];
      try {
        return window.localStorage.getItem(STORAGE_KEY) || "";
      } catch (e) {
        return "";
      }
    }

    // Restore the persisted tab on page load. If nothing is persisted, the
    // server-rendered default ("settings") stays active.
    var persisted = readPersistedTab();
    if (persisted) activateTab(persisted);

    $(".iws-tabs__tab").on("click", function () {
      var tab = $(this).data("tab");
      if (!activateTab(tab)) return;
      persistTab(tab);

      // Refresh log when switching to the log tab.
      if ("log" === tab) {
        refreshLog();
      }
    });

    /* ---------------------------------------------------------------
	   Helpers
	   --------------------------------------------------------------- */

    function showNotice(type, message) {
      var $el = $("#iws-notices");
      $el.html(
        $("<div>")
          .addClass("iws-notice iws-notice--" + type)
          .text(message),
      );
      setTimeout(function () {
        $el.find(".iws-notice").fadeOut(300, function () {
          $(this).remove();
        });
      }, 6000);
    }

    function ajaxPost(action, extra) {
      var data = $.extend(
        {
          action: action,
          nonce: etherlabzIntercomAdmin.nonce,
        },
        extra || {},
      );

      return $.post(etherlabzIntercomAdmin.ajaxUrl, data);
    }

    /* ---------------------------------------------------------------
	   Test Connection
	   --------------------------------------------------------------- */

    $("#iws-test-connection").on("click", function () {
      var $btn = $(this);
      var $spinner = $("#iws-test-spinner");
      var $result = $("#iws-test-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("etherlabz_intercom_test_connection")
        .done(function (res) {
          if (res.success) {
            $result.text(res.data.message).addClass("success");
          } else {
            $result.text(res.data.message).addClass("error");
          }
        })
        .fail(function () {
          $result.text(i18n.requestFailed || "Request failed.").addClass("error");
        })
        .always(function () {
          $btn.prop("disabled", false);
          $spinner.removeClass("is-active");
        });
    });

    /* ---------------------------------------------------------------
	   Bulk Sync
	   --------------------------------------------------------------- */

    $("#iws-start-bulk-sync").on("click", function () {
      var $btn = $(this);
      var $spinner = $("#iws-bulk-spinner");
      var $result = $("#iws-bulk-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("etherlabz_intercom_bulk_sync")
        .done(function (res) {
          if (res.success) {
            $result.text(res.data.message).addClass("success");
            showNotice("success", res.data.message);
            pollBulkStatus();
          } else {
            $result.text(res.data.message).addClass("error");
            $btn.prop("disabled", false);
          }
        })
        .fail(function () {
          $result.text(i18n.requestFailed || "Request failed.").addClass("error");
          $btn.prop("disabled", false);
        })
        .always(function () {
          $spinner.removeClass("is-active");
        });
    });

    /**
     * Poll bulk sync status every 10 seconds.
     */
    function pollBulkStatus() {
      var interval = setInterval(function () {
        ajaxPost("etherlabz_intercom_bulk_sync_status").done(function (res) {
          if (!res.success) {
            clearInterval(interval);
            return;
          }

          var $status = $("#iws-bulk-status");

          if (res.data.running) {
            var runningBadge = $("<span>")
              .addClass("iws-badge iws-badge--running")
              .html(
                '<span class="dashicons dashicons-update iws-spin"></span> ' +
                escHtml(i18n.running || "Running…"),
              );
            var offsetText = $("<span>")
              .addClass("iws-bulk-offset")
              .text(res.data.offset + " " + (i18n.customersCount || "customers processed so far"));

            $status.empty().append(runningBadge).append(offsetText);
          } else {
            $status.html(
              $("<span>")
                .addClass("iws-badge iws-badge--idle")
                .text(i18n.idle || "Idle"),
            );
            $("#iws-start-bulk-sync").prop("disabled", false);
            $("#iws-bulk-result")
              .text(i18n.bulkComplete || "Bulk sync complete.")
              .addClass("success");
            clearInterval(interval);
          }
        });
      }, 10000);
    }

    // Start polling if already running on page load.
    if ($(".iws-badge--running").length) {
      pollBulkStatus();
    }

    /* ---------------------------------------------------------------
	   Clear Log
	   --------------------------------------------------------------- */

    $("#iws-clear-log").on("click", function () {
      // eslint-disable-next-line no-alert
      if (!window.confirm(i18n.clearLogConfirm || "Clear the entire sync log?")) {
        return;
      }

      ajaxPost("etherlabz_intercom_clear_log").done(function (res) {
        if (res.success) {
          $("#iws-log-table-wrap").html(
            $("<p>").addClass("iws-empty").text(i18n.noLogEntries || "No log entries yet."),
          );
          showNotice("success", res.data.message);
        }
      });
    });

    /* ---------------------------------------------------------------
	   Refresh Log
	   --------------------------------------------------------------- */

    function refreshLog() {
      ajaxPost("etherlabz_intercom_get_log").done(function (res) {
        if (!res.success || !res.data.log || 0 === res.data.log.length) {
          $("#iws-log-table-wrap").html(
            $("<p>").addClass("iws-empty").text(i18n.noLogEntries || "No log entries yet."),
          );
          return;
        }

        var $table = $('<table class="widefat striped iws-log-table">');
        var $thead = $("<thead>").append(
          $("<tr>").append(
            $("<th>").text(i18n.logColTime || "Time"),
            $("<th>").text(i18n.logColStatus || "Status"),
            $("<th>").text(i18n.logColAction || "Action"),
            $("<th>").text(i18n.logColMessage || "Message"),
          ),
        );
        var $tbody = $("<tbody>");

        $.each(res.data.log, function (i, entry) {
          var isSuccess = "success" === entry.status;
          var $badge = $("<span>")
            .addClass("iws-badge " + (isSuccess ? "iws-badge--success" : "iws-badge--error"))
            .text(isSuccess ? (i18n.badgeOk || "OK") : (i18n.badgeError || "Error"));

          $tbody.append(
            $("<tr>").append(
              $("<td>").append($("<code>").text(entry.time || "")),
              $("<td>").append($badge),
              $("<td>").append($("<code>").text(entry.action || "")),
              $("<td>").text(entry.msg || ""),
            ),
          );
        });

        $table.append($thead).append($tbody);
        $("#iws-log-table-wrap").html($table);
      });
    }

    /* ---------------------------------------------------------------
	   Register Attributes
	   --------------------------------------------------------------- */

    $("#iws-register-attrs").on("click", function () {
      var $btn = $(this);
      var $spinner = $("#iws-attrs-spinner");
      var $result = $("#iws-attrs-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("etherlabz_intercom_register_attributes")
        .done(function (res) {
          if (res.success) {
            $result.text(res.data.message).addClass("success");
            showNotice("success", res.data.message);
          } else {
            $result.text(res.data.message).addClass("error");
          }
        })
        .fail(function () {
          $result.text(i18n.requestFailed || "Request failed.").addClass("error");
        })
        .always(function () {
          $btn.prop("disabled", false);
          $spinner.removeClass("is-active");
        });
    });

    /* ---------------------------------------------------------------
	   Generate Fin API Key
	   --------------------------------------------------------------- */

    $("#iws-generate-fin-key").on("click", function () {
      if (
        $("#iws-fin-key-value").length &&
        // eslint-disable-next-line no-alert
        !window.confirm(
          i18n.regenKeyConfirm || "This will invalidate the current key. Continue?",
        )
      ) {
        return;
      }

      var $btn = $(this);
      var $spinner = $("#iws-finkey-spinner");
      var $result = $("#iws-finkey-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("etherlabz_intercom_generate_fin_key")
        .done(function (res) {
          if (res.success) {
            $result.text(res.data.message).addClass("success");

            // Show the key for copying.
            $("#iws-fin-key-full").val(res.data.key);
            $("#iws-fin-key-reveal").removeClass("iws-hidden").hide().slideDown(200);

            // Update the masked display.
            var masked =
              res.data.key.substring(0, 8) +
              new Array(res.data.key.length - 7).join("*");
            if ($("#iws-fin-key-value").length) {
              $("#iws-fin-key-value").text(masked);
            }

            showNotice("success", i18n.keyGenerated || "API key generated successfully.");
          } else {
            $result.text(res.data.message).addClass("error");
          }
        })
        .fail(function () {
          $result.text(i18n.requestFailed || "Request failed.").addClass("error");
        })
        .always(function () {
          $btn.prop("disabled", false);
          $spinner.removeClass("is-active");
        });
    });

    /* ---------------------------------------------------------------
	   Copy Fin API Key
	   --------------------------------------------------------------- */

    $(document).on("click", "#iws-copy-fin-key", function () {
      var $input = $("#iws-fin-key-full");
      var keyValue = $input.val();

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(keyValue).then(function () {
          showNotice("success", i18n.keyCopied || "API key copied to clipboard.");
        });
      } else {
        $input.select();
        document.execCommand("copy");
        showNotice("success", i18n.keyCopied || "API key copied to clipboard.");
      }
    });

    /**
     * Minimal HTML escaping — used only for trusted server values in badge text.
     *
     * @param {string} str
     * @return {string}
     */
    function escHtml(str) {
      var div = document.createElement("div");
      div.appendChild(document.createTextNode(str));
      return div.innerHTML;
    }

    /* ---------------------------------------------------------------
       Live Stream — auto-refresh sync log with filters
       --------------------------------------------------------------- */

    var streamTimer = null;
    var lastSeenTime = "";
    var STREAM_INTERVAL = 5000;

    function fetchAndRenderLog() {
      var status = $("#iws-filter-status").val() || "all";
      var actionQ = ($("#iws-filter-action").val() || "").trim();

      ajaxPost("etherlabz_intercom_get_log_filtered", {
        status: status,
        action_q: actionQ,
        // Don't pass `since` — we always do a full re-render to handle filter changes.
      }).done(function (res) {
        if (!res || !res.success) return;
        renderLogTable(res.data.log || []);
      });
    }

    function renderLogTable(entries) {
      var $wrap = $("#iws-log-table-wrap");

      if (!entries.length) {
        $wrap.html(
          $("<p>")
            .addClass("iws-empty")
            .text(i18n.noLogEntries || "No log entries yet."),
        );
        lastSeenTime = "";
        return;
      }

      var newestTime = entries[0].time || "";
      var $table = $('<table class="widefat striped iws-log-table">');
      var $thead = $("<thead>").append(
        $("<tr>").append(
          $("<th>").text(i18n.logColTime || "Time"),
          $("<th>").text(i18n.logColStatus || "Status"),
          $("<th>").text(i18n.logColAction || "Action"),
          $("<th>").text(i18n.logColMessage || "Message"),
        ),
      );
      var $tbody = $("<tbody>");

      $.each(entries, function (i, entry) {
        var isSuccess = "success" === entry.status;
        var $badge = $("<span>")
          .addClass(
            "iws-badge " + (isSuccess ? "iws-badge--success" : "iws-badge--error"),
          )
          .text(isSuccess ? i18n.badgeOk || "OK" : i18n.badgeError || "Error");

        var $row = $("<tr>").append(
          $("<td>").append($("<code>").text(entry.time || "")),
          $("<td>").append($badge),
          $("<td>").append($("<code>").text(entry.action || "")),
          $("<td>").text(entry.msg || ""),
        );

        // Highlight rows newer than the last seen timestamp (live append).
        if (lastSeenTime && entry.time && entry.time > lastSeenTime) {
          $row.addClass("iws-row--new");
        }

        $tbody.append($row);
      });

      $table.append($thead).append($tbody);
      $wrap.html($table);

      lastSeenTime = newestTime;
    }

    function startStream() {
      stopStream();
      $("#iws-stream-indicator")
        .addClass("iws-stream-bar__live--on")
        .find(".iws-stream-bar__live-label")
        .text(i18n.live || "Live");
      streamTimer = setInterval(fetchAndRenderLog, STREAM_INTERVAL);
    }

    function stopStream() {
      if (streamTimer) {
        clearInterval(streamTimer);
        streamTimer = null;
      }
      $("#iws-stream-indicator")
        .removeClass("iws-stream-bar__live--on")
        .find(".iws-stream-bar__live-label")
        .text(i18n.paused || "Paused");
    }

    // Filter change → re-render immediately + reset live highlight watermark.
    $(document).on("change keyup", "#iws-filter-status, #iws-filter-action", function () {
      lastSeenTime = "";
      fetchAndRenderLog();
    });

    // Live toggle.
    $(document).on("change", "#iws-stream-toggle", function () {
      if (this.checked) startStream();
      else stopStream();
    });

    // Pause when the browser tab is hidden, resume on visible.
    $(document).on("visibilitychange", function () {
      if (document.hidden) {
        if (streamTimer) clearInterval(streamTimer);
      } else if ($("#iws-stream-toggle").is(":checked")) {
        startStream();
      }
    });

    // When user activates the Live Stream tab, do an immediate fetch + start polling.
    $(".iws-tabs__tab[data-tab='log']").on("click", function () {
      lastSeenTime = "";
      fetchAndRenderLog();
      if ($("#iws-stream-toggle").is(":checked")) startStream();
    });

    // If page loads with the log tab already active, start streaming right away.
    if ($("#iws-panel-log").hasClass("iws-tab-panel--active")) {
      startStream();
    }

    /* ---------------------------------------------------------------
       Fin Action toggles — confirmation prompts on the dangerous ones
       --------------------------------------------------------------- */

    $(document).on("change", ".iws-fin-action-toggle", function () {
      if (!this.checked) return; // only warn when turning ON

      var action = $(this).data("action");
      var msg = "";
      if (action === "cancel") msg = i18n.finCancelWarn;
      else if (action === "refund") msg = i18n.finRefundWarn;
      else if (action === "note") msg = i18n.finNoteWarn;

      // eslint-disable-next-line no-alert
      if (msg && !window.confirm(msg)) {
        this.checked = false;
      }
    });

    /* ---------------------------------------------------------------
       Segments — rule builder
       --------------------------------------------------------------- */

    var segmentFields = (etherlabzIntercomAdmin.segments && etherlabzIntercomAdmin.segments.fields) || [];
    var segmentOperators = (etherlabzIntercomAdmin.segments && etherlabzIntercomAdmin.segments.operators) || {};

    function fieldType(fieldKey) {
      for (var i = 0; i < segmentFields.length; i++) {
        if (segmentFields[i].key === fieldKey) return segmentFields[i].type;
      }
      return "string";
    }

    function buildOperatorOptions(type, selected) {
      var ops = segmentOperators[type] || [];
      var $sel = $('<select class="iws-condition__op">');
      $.each(ops, function (i, op) {
        var $opt = $("<option>").attr("value", op.key).text(op.label);
        if (op.key === selected) $opt.prop("selected", true);
        $sel.append($opt);
      });
      return $sel;
    }

    function buildFieldSelect(selected) {
      var $sel = $('<select class="iws-condition__field">');
      $.each(segmentFields, function (i, f) {
        var $opt = $("<option>").attr("value", f.key).text(f.label);
        if (f.key === selected) $opt.prop("selected", true);
        $sel.append($opt);
      });
      return $sel;
    }

    function buildCondition(cond) {
      cond = cond || { field: segmentFields[0] && segmentFields[0].key, operator: "", value: "" };
      var $row = $('<div class="iws-condition">');
      var $field = buildFieldSelect(cond.field);
      var type = fieldType(cond.field);
      var $op = buildOperatorOptions(type, cond.operator);
      var $val = $('<input type="text" class="iws-condition__value" />').val(cond.value || "");
      var $remove = $('<button type="button" class="iws-condition__remove">×</button>').attr(
        "aria-label",
        i18n.removeCondition || "Remove",
      );

      $field.on("change", function () {
        var newType = fieldType($(this).val());
        $op.replaceWith(buildOperatorOptions(newType, ""));
        $op = $row.find(".iws-condition__op");
      });

      $remove.on("click", function () {
        $row.remove();
        refreshEmptyState();
      });

      $row.append($field).append($op).append($val).append($remove);
      return $row;
    }

    function buildRule(rule) {
      rule = rule || {
        id: "",
        name: "",
        tag: "",
        match: "all",
        enabled: true,
        conditions: [{}],
      };

      var $card = $('<div class="iws-rule">').data("ruleId", rule.id || "");
      var $head = $('<div class="iws-rule__head">');

      $head.append(
        $('<input type="text" class="iws-rule__name">').attr(
          "placeholder",
          i18n.ruleName || "Rule name",
        ).val(rule.name || ""),
      );
      $head.append(
        $('<input type="text" class="iws-rule__tag">').attr(
          "placeholder",
          i18n.ruleTag || "Tag",
        ).val(rule.tag || ""),
      );

      var $matchSel = $('<select class="iws-rule__match">');
      $matchSel.append(
        $("<option>").attr("value", "all").text(i18n.ruleMatchAll || "Match ALL conditions"),
      );
      $matchSel.append(
        $("<option>").attr("value", "any").text(i18n.ruleMatchAny || "Match ANY condition"),
      );
      $matchSel.val(rule.match === "any" ? "any" : "all");
      $head.append($matchSel);

      var $enabled = $('<label class="iws-rule__enabled">').append(
        $('<input type="checkbox" class="iws-rule__enabled-cb">').prop(
          "checked",
          rule.enabled !== false,
        ),
        " ",
        $("<span>").text(i18n.ruleEnabled || "Enabled"),
      );
      $head.append($enabled);

      var $delete = $('<button type="button" class="iws-rule__delete">').text(
        i18n.deleteRule || "Delete",
      );
      $delete.on("click", function () {
        if (window.confirm(i18n.deleteRuleConfirm || "Delete this rule?")) {
          $card.remove();
          refreshEmptyState();
        }
      });
      $head.append($delete);

      $card.append($head);

      var $conds = $('<div class="iws-rule__conditions">');
      var rConds = rule.conditions && rule.conditions.length ? rule.conditions : [{}];
      $.each(rConds, function (i, c) {
        $conds.append(buildCondition(c));
      });
      $card.append($conds);

      var $addCond = $('<button type="button" class="iws-rule__add-cond">').text(
        i18n.addCondition || "+ Add condition",
      );
      $addCond.on("click", function () {
        $conds.append(buildCondition());
      });
      $card.append($addCond);

      return $card;
    }

    function collectRules() {
      var rules = [];
      $("#iws-rules-list .iws-rule").each(function () {
        var $card = $(this);
        var conditions = [];
        $card.find(".iws-condition").each(function () {
          var $c = $(this);
          conditions.push({
            field: $c.find(".iws-condition__field").val(),
            operator: $c.find(".iws-condition__op").val(),
            value: $c.find(".iws-condition__value").val(),
          });
        });
        rules.push({
          id: $card.data("ruleId") || "",
          name: $card.find(".iws-rule__name").val(),
          tag: $card.find(".iws-rule__tag").val(),
          match: $card.find(".iws-rule__match").val(),
          enabled: $card.find(".iws-rule__enabled-cb").is(":checked"),
          conditions: conditions,
        });
      });
      return rules;
    }

    function refreshEmptyState() {
      var $list = $("#iws-rules-list");
      var hasRules = $list.find(".iws-rule").length > 0;
      $list.find(".iws-rules-empty").toggle(!hasRules);
    }

    function loadInitialRules() {
      var $list = $("#iws-rules-list");
      if (!$list.length) return;
      var raw = $list.attr("data-rules") || "[]";
      var rules;
      try {
        rules = JSON.parse(raw);
      } catch (e) {
        rules = [];
      }
      if (!Array.isArray(rules) || rules.length === 0) {
        refreshEmptyState();
        return;
      }
      $list.find(".iws-rules-empty").remove();
      $.each(rules, function (i, r) {
        $list.append(buildRule(r));
      });
    }

    $("#iws-add-rule").on("click", function () {
      $("#iws-rules-list").find(".iws-rules-empty").remove();
      $("#iws-rules-list").append(buildRule());
    });

    $("#iws-save-segments").on("click", function () {
      var $btn = $(this);
      var $spinner = $("#iws-segments-spinner");
      var $result = $("#iws-segments-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("etherlabz_intercom_save_segments", { rules: JSON.stringify(collectRules()) })
        .done(function (res) {
          if (res.success) {
            $result.text(i18n.segmentsSaved || "Segment rules saved.").addClass("success");
            showNotice("success", i18n.segmentsSaved || "Segment rules saved.");
          } else {
            $result.text((res.data && res.data.message) || "Save failed.").addClass("error");
          }
        })
        .fail(function () {
          $result.text(i18n.requestFailed || "Request failed.").addClass("error");
        })
        .always(function () {
          $btn.prop("disabled", false);
          $spinner.removeClass("is-active");
        });
    });

    loadInitialRules();

    /* ---------------------------------------------------------------
	   Secret fields — explicit Change / Cancel flow
	   ---------------------------------------------------------------
	   The stored value never renders in the page; while the input is
	   hidden it is also disabled, so nothing submits and the saved
	   value is kept. "Change" arms the input for a replacement. */

    $(document).on("click", ".iws-secret__change", function () {
      var $wrap = $(this).closest(".iws-secret");
      $wrap.find(".iws-secret__chip, .iws-secret__actions").addClass("iws-hidden");
      $wrap
        .find(".iws-secret__editor")
        .removeClass("iws-hidden")
        .find("input")
        .prop("disabled", false)
        .trigger("focus");
    });

    $(document).on("click", ".iws-secret__cancel", function () {
      var $wrap = $(this).closest(".iws-secret");
      $wrap
        .find(".iws-secret__editor")
        .addClass("iws-hidden")
        .find("input")
        .val("")
        .prop("disabled", true);
      $wrap.find(".iws-secret__chip, .iws-secret__actions").removeClass("iws-hidden");
    });

    $(document).on("click", ".iws-secret__remove", function () {
      // eslint-disable-next-line no-alert
      if (
        !window.confirm(
          i18n.removeSecretConfirm ||
            "Remove this saved value? Related features stop working until a new one is saved.",
        )
      ) {
        return;
      }
      var $wrap = $(this).closest(".iws-secret");
      $wrap.find(".iws-secret__remove-flag").val("1");
      $wrap.find(".iws-secret__chip").addClass("iws-secret__chip--pending");
      $wrap.find(".iws-secret__actions").addClass("iws-hidden");
      $wrap.find(".iws-secret__pending").removeClass("iws-hidden");
    });

    $(document).on("click", ".iws-secret__undo", function () {
      var $wrap = $(this).closest(".iws-secret");
      $wrap.find(".iws-secret__remove-flag").val("");
      $wrap.find(".iws-secret__chip").removeClass("iws-secret__chip--pending");
      $wrap.find(".iws-secret__pending").addClass("iws-hidden");
      $wrap.find(".iws-secret__actions").removeClass("iws-hidden");
    });
  }); // end document.ready
})(jQuery);

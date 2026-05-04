/**
 * Intercom WooCommerce Sync — Admin JavaScript
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

(function ($) {
  "use strict";

  var i18n = (iwsAdmin && iwsAdmin.i18n) ? iwsAdmin.i18n : {};

  $(document).ready(function () {
    /* ---------------------------------------------------------------
	   Tab navigation
	   --------------------------------------------------------------- */

    $(".iws-tabs__tab").on("click", function () {
      var tab = $(this).data("tab");

      // Update tabs.
      $(".iws-tabs__tab")
        .removeClass("iws-tabs__tab--active")
        .attr("aria-selected", "false");
      $(this).addClass("iws-tabs__tab--active").attr("aria-selected", "true");

      // Update panels.
      $(".iws-tab-panel").removeClass("iws-tab-panel--active");
      $("#iws-panel-" + tab).addClass("iws-tab-panel--active");

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
          nonce: iwsAdmin.nonce,
        },
        extra || {},
      );

      return $.post(iwsAdmin.ajaxUrl, data);
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

      ajaxPost("iws_test_connection")
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

      ajaxPost("iws_bulk_sync")
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
        ajaxPost("iws_bulk_sync_status").done(function (res) {
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

      ajaxPost("iws_clear_log").done(function (res) {
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
      ajaxPost("iws_get_log").done(function (res) {
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

      ajaxPost("iws_register_attributes")
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
        !window.confirm("This will invalidate the current key. Continue?")
      ) {
        return;
      }

      var $btn = $(this);
      var $spinner = $("#iws-finkey-spinner");
      var $result = $("#iws-finkey-result");

      $btn.prop("disabled", true);
      $spinner.addClass("is-active");
      $result.text("").removeClass("success error");

      ajaxPost("iws_generate_fin_key")
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
  }); // end document.ready
})(jQuery);

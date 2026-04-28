/**
 * Intercom WooCommerce Sync — Admin JavaScript
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

(function ($) {
  "use strict";

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
        '<div class="iws-notice iws-notice--' +
          type +
          '">' +
          message +
          "</div>",
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
          $result.text("Request failed.").addClass("error");
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
          $result.text("Request failed.").addClass("error");
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
            $status.html(
              '<span class="iws-badge iws-badge--running">' +
                '<span class="dashicons dashicons-update iws-spin"></span> Running&hellip;</span>' +
                '<span class="iws-bulk-offset">' +
                res.data.offset +
                " customers processed so far</span>",
            );
          } else {
            $status.html('<span class="iws-badge iws-badge--idle">Idle</span>');
            $("#iws-start-bulk-sync").prop("disabled", false);
            $("#iws-bulk-result")
              .text("Bulk sync complete.")
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
      if (!confirm("Clear the entire sync log?")) {
        return;
      }

      ajaxPost("iws_clear_log").done(function (res) {
        if (res.success) {
          $("#iws-log-table-wrap").html(
            '<p class="iws-empty">No log entries yet.</p>',
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
            '<p class="iws-empty">No log entries yet.</p>',
          );
          return;
        }

        var html =
          '<table class="widefat striped iws-log-table">' +
          "<thead><tr>" +
          "<th>Time</th><th>Status</th><th>Action</th><th>Message</th>" +
          "</tr></thead><tbody>";

        $.each(res.data.log, function (i, entry) {
          var badge =
            "success" === entry.status
              ? '<span class="iws-badge iws-badge--success">OK</span>'
              : '<span class="iws-badge iws-badge--error">Error</span>';

          html +=
            "<tr>" +
            "<td><code>" +
            escHtml(entry.time || "") +
            "</code></td>" +
            "<td>" +
            badge +
            "</td>" +
            "<td><code>" +
            escHtml(entry.action || "") +
            "</code></td>" +
            "<td>" +
            escHtml(entry.msg || "") +
            "</td>" +
            "</tr>";
        });

        html += "</tbody></table>";
        $("#iws-log-table-wrap").html(html);
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
          $result.text("Request failed.").addClass("error");
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
        !confirm("This will invalidate the current key. Continue?")
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
            $("#iws-fin-key-reveal").slideDown(200);

            // Update the masked display.
            var masked =
              res.data.key.substring(0, 8) +
              new Array(res.data.key.length - 7).join("*");
            if ($("#iws-fin-key-value").length) {
              $("#iws-fin-key-value").text(masked);
            }

            showNotice("success", "API key generated successfully.");
          } else {
            $result.text(res.data.message).addClass("error");
          }
        })
        .fail(function () {
          $result.text("Request failed.").addClass("error");
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
      $input.select();

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText($input.val()).then(function () {
          showNotice("success", "API key copied to clipboard.");
        });
      } else {
        document.execCommand("copy");
        showNotice("success", "API key copied to clipboard.");
      }
    });

    /**
     * Minimal HTML escaping.
     */
    function escHtml(str) {
      var div = document.createElement("div");
      div.appendChild(document.createTextNode(str));
      return div.innerHTML;
    }
  }); // end document.ready
})(jQuery);

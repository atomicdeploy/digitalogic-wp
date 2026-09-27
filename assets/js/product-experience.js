(function () {
  "use strict";

  function neutralizeHoneypots(root) {
    if (!root || root.nodeType !== 1) return;

    var honeypots = [];
    if (root.matches && root.matches(".altEmail_container")) honeypots.push(root);
    if (root.querySelectorAll) {
      root.querySelectorAll(".altEmail_container").forEach(function (honeypot) {
        honeypots.push(honeypot);
      });
    }

    honeypots.forEach(function (honeypot) {
      honeypot.setAttribute("aria-hidden", "true");
      honeypot.querySelectorAll("input, label").forEach(function (field) {
        if (field.matches("input")) field.setAttribute("tabindex", "-1");
        field.setAttribute("aria-hidden", "true");
      });
    });
  }

  function ready() {
    var body = document.body;
    if (!body || !body.classList.contains("dgl-product-experience")) return;

    body.classList.add("dgl-product-experience--ready");

    var overview = document.querySelector(".dgl-product-description-card");
    var specs = document.querySelector(".dgl-product-specs-card");
    var reviews = document.querySelector(".dgl-product-reviews");

    if (overview && !overview.id) overview.id = "dgl-product-overview";
    if (specs && !specs.id) specs.id = "dgl-product-specs";
    if (reviews && !reviews.id) reviews.id = "dgl-product-reviews";

    neutralizeHoneypots(body);
    if ("MutationObserver" in window) {
      var honeypotObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            neutralizeHoneypots(node);
          });
        });
      });
      honeypotObserver.observe(body, { childList: true, subtree: true });
    }

    document.querySelectorAll(".woocommerce-product-gallery").forEach(function (gallery) {
      var images = gallery.querySelectorAll(".woocommerce-product-gallery__image");
      if (images.length <= 1) gallery.classList.add("dgl-single-image");

      var placeholder = gallery.querySelector(".woocommerce-placeholder, img[src*='woocommerce-placeholder']");
      var media = gallery.closest(".dgl-product-media");
      if (placeholder && media) {
        media.classList.add("dgl-product-media--pending");
        var frame = media.querySelector(".elementor-widget-wrap");
        if (frame && !frame.querySelector(".dgl-media-status")) {
          var status = document.createElement("div");
          status.className = "dgl-media-status";
          status.setAttribute("role", "status");
          status.innerHTML = "<strong>تصویر محصول در حال تکمیل است</strong><span>مشخصات و کد کالا برای سفارش قابل بررسی است.</span>";
          frame.appendChild(status);
        }
      }
    });

    function escapedPattern(value) {
      return String(value || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    }

    function contextualValue(form, key, variation) {
      var value = "";
	  var code = variation ? String(variation.digitalogic_product_code || "").trim() : "";
	  if ((key === "source_model" || key === "pa_source_model") && variation) {
		value = String(variation.digitalogic_product_name || variation.digitalogic_persian_name || "").trim();
		if (value) return value;
	  }
      form.querySelectorAll(".variations select").forEach(function (select) {
        if (select.name !== "attribute_" + key || !select.value) return;
        var selected = select.options[select.selectedIndex];
        value = selected ? String(selected.textContent || "").trim() : "";
      });
      if (!value && variation && variation.attributes) {
        value = String(variation.attributes["attribute_" + key] || "").trim();
      }
	  if (code && value) {
		value = value.replace(new RegExp("\\s*[\\[(]\\s*" + escapedPattern(code) + "\\s*[\\])]\\s*$", "i"), "").trim();
	  }
      return value;
    }

    function syncContextualAttributes(form, variation) {
      var scope = form.closest(".product-quick-view, .single-product-page, .product") || document;
      scope.querySelectorAll("[data-digitalogic-context-attribute]").forEach(function (row) {
        var key = row.getAttribute("data-digitalogic-context-attribute") || "";
        var value = variation ? contextualValue(form, key, variation) : "";
        var target = row.querySelector(".dgl-highlight__copy strong bdi, td bdi");
        if (target && value) target.textContent = value;
        row.hidden = !value;
      });
	  scope.querySelectorAll("[data-digitalogic-context-product-code]").forEach(function (row) {
		var code = variation ? String(variation.digitalogic_product_code || "").trim() : "";
		var value = row.querySelector(".dgl-highlight__copy strong bdi");
		var button = row.querySelector("[data-digitalogic-copy-product-code]");
		if (value) value.textContent = code;
		if (button) button.setAttribute("data-copy-text", code);
		row.hidden = !code;
	  });
    }

	function copyProductCode(button) {
	  var code = String(button.getAttribute("data-copy-text") || "").trim();
	  if (!code) return;
	  function complete() {
		button.classList.add("is-copied");
		button.setAttribute("aria-label", "کد کالا کپی شد");
		button.setAttribute("title", "کپی شد");
		window.setTimeout(function () {
		  button.classList.remove("is-copied");
		  button.setAttribute("aria-label", "کپی کد کالا");
		  button.setAttribute("title", "کپی کد کالا");
		}, 1600);
	  }
	  if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(code).then(complete).catch(function () {});
		return;
	  }
	  var input = document.createElement("textarea");
	  input.value = code;
	  input.setAttribute("readonly", "");
	  input.style.position = "fixed";
	  input.style.opacity = "0";
	  document.body.appendChild(input);
	  input.select();
	  try { if (document.execCommand("copy")) complete(); } catch (_) {}
	  input.remove();
	}

	document.addEventListener("click", function (event) {
	  var button = event.target.closest("[data-digitalogic-copy-product-code]");
	  if (button) copyProductCode(button);
	});

    document.querySelectorAll("form.variations_form").forEach(function (form) {
      var variationButton = form.querySelector(".single_add_to_cart_button");
      var buyNowButton = form.querySelector(".wd-buy-now-btn");
	  syncContextualAttributes(form, null);
      if (!variationButton || !buyNowButton) return;

      function syncVariationActions() {
        var needsSelection =
          variationButton.disabled ||
          variationButton.classList.contains("disabled") ||
          variationButton.classList.contains("wc-variation-selection-needed") ||
          variationButton.classList.contains("wc-variation-is-unavailable");

        buyNowButton.disabled = needsSelection;
        buyNowButton.classList.toggle("is-disabled", needsSelection);
        buyNowButton.setAttribute("aria-disabled", needsSelection ? "true" : "false");
      }

      if ("MutationObserver" in window) {
        var variationButtonObserver = new MutationObserver(syncVariationActions);
        variationButtonObserver.observe(variationButton, { attributes: true, attributeFilter: ["class", "disabled"] });
      }

      form.addEventListener("change", function () {
        window.setTimeout(syncVariationActions, 0);
      });
      buyNowButton.addEventListener("click", function (event) {
        if (buyNowButton.getAttribute("aria-disabled") !== "true") return;
        event.preventDefault();
        event.stopImmediatePropagation();
      });
      syncVariationActions();
    });

    if (window.jQuery) {
      window.jQuery(document)
        .on("found_variation.digitalogicContext", "form.variations_form", function (_event, variation) {
          syncContextualAttributes(this, variation);
        })
        .on("reset_data.digitalogicContext hide_variation.digitalogicContext", "form.variations_form", function () {
          syncContextualAttributes(this, null);
        });
    }

    var navLinks = Array.prototype.slice.call(document.querySelectorAll(".dgl-section-nav__links a[href^='#']"));
    navLinks.forEach(function (link) {
      link.addEventListener("click", function (event) {
        var target = document.querySelector(link.getAttribute("href"));
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ behavior: "smooth", block: "start" });
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, "", link.getAttribute("href"));
        }
      });
    });

    if ("IntersectionObserver" in window && navLinks.length) {
      var sections = navLinks
        .map(function (link) {
          return document.querySelector(link.getAttribute("href"));
        })
        .filter(Boolean);

      var sectionObserver = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            navLinks.forEach(function (link) {
              link.classList.toggle("is-active", link.getAttribute("href") === "#" + entry.target.id);
            });
          });
        },
        { rootMargin: "-25% 0px -65% 0px", threshold: 0 }
      );

      sections.forEach(function (section) {
        sectionObserver.observe(section);
      });
    }

    var mobileBar = document.querySelector(".dgl-mobile-purchase-bar");
    var purchasePanel = document.querySelector(".dgl-product-purchase");
    var primaryButton = document.querySelector(".dgl-product-purchase .single_add_to_cart_button");

    if (!mobileBar || !purchasePanel) return;

    var mobileButton = mobileBar.querySelector(".dgl-mobile-purchase-bar__button");
    var productType = mobileBar.getAttribute("data-product-type") || "";

    function syncMobileButton() {
      if (!primaryButton || !mobileButton) return;
      var disabled = primaryButton.disabled || primaryButton.classList.contains("disabled") || primaryButton.classList.contains("wc-variation-selection-needed");
      mobileButton.disabled = productType === "simple" ? disabled : false;
    }

    if (primaryButton && "MutationObserver" in window) {
      var buttonObserver = new MutationObserver(syncMobileButton);
      buttonObserver.observe(primaryButton, { attributes: true, attributeFilter: ["class", "disabled"] });
      syncMobileButton();
    }

    if (mobileButton) {
      mobileButton.addEventListener("click", function () {
        var isSimple = productType === "simple";
        if (isSimple && primaryButton && !mobileButton.disabled) {
          primaryButton.click();
          return;
        }

        purchasePanel.scrollIntoView({ behavior: "smooth", block: "center" });
        var firstControl = purchasePanel.querySelector("select, input:not([type='hidden']), button");
        if (firstControl) {
          window.setTimeout(function () {
            firstControl.focus({ preventScroll: true });
          }, 450);
        }
      });
    }

    if ("IntersectionObserver" in window) {
      var purchaseObserver = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            var shouldShow = !entry.isIntersecting && window.matchMedia("(max-width: 767px)").matches;
            mobileBar.classList.toggle("is-visible", shouldShow);
            mobileBar.setAttribute("aria-hidden", shouldShow ? "false" : "true");
          });
        },
        { threshold: 0.12 }
      );
      purchaseObserver.observe(purchasePanel);
    }

    if (window.jQuery) {
      window.jQuery(document.body).on("found_variation", function (_event, variation) {
        window.setTimeout(syncMobileButton, 0);
      });

      window.jQuery(document.body).on("reset_data hide_variation", function () {
        window.setTimeout(syncMobileButton, 0);
      });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready, { once: true });
  } else {
    ready();
  }
})();

/**
 * ShareGate 嵌入「创建付费分享」页 — 与平台无关的共享逻辑
 * 用于：ShareGate Server (Express) / sharegate-nextcloud (NC App)
 *
 * 初始化：ShareGateEmbedCreate.init(config)
 */
(function (global) {
  "use strict";

  function serverDefaults() {
    var params = new URLSearchParams(global.location.search);
    return {
      platform: "server",
      createUrl: (params.get("api") || global.location.origin).replace(/\/+$/, "") + "/share/create",
      storageType: params.get("storage") || "alist",
      requireQueryParams: true,
      pathEditable: false,
      authType: "apiKey",
      fileInfoUrl: null,
      publicBase:
        params.get("publicBase") ||
        global.__SHAREGATE_PUBLIC_BASE__ ||
        global.location.origin,
      adminLink: null,
      missingParamsMessage: "缺少参数：请从 AList 右键「付费分享」打开此页面",
      initialPath: decodeURIComponent(params.get("path") || ""),
      initialName: decodeURIComponent(params.get("name") || ""),
      initialSize: params.get("size") ? Number(params.get("size")) : 0,
    };
  }

  function getApiKey() {
    var stored = global.sessionStorage && global.sessionStorage.getItem("sg_api_key");
    if (stored) return stored;
    if (global.SHAREGATE_EMBED_API_KEY) return global.SHAREGATE_EMBED_API_KEY;
    return "";
  }

  var runtimeConfig = null;

  function apiFetch(url, opts, authType) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers["Content-Type"] = "application/json";
    if (authType === "apiKey") {
      var key = getApiKey();
      if (key) opts.headers["x-api-key"] = key;
    } else if (authType === "ncCsrf") {
      var token =
        (global.OC && global.OC.requestToken) ||
        (runtimeConfig && runtimeConfig.requestToken);
      if (token) opts.headers.requesttoken = token;
    }
    return global.fetch(url, opts);
  }

  function showToast(msg, type) {
    var t = global.document.createElement("div");
    t.className = "toast toast-" + (type || "success");
    t.textContent = msg;
    global.document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3000);
  }

  function init(userConfig) {
    var config = Object.assign(serverDefaults(), userConfig || {});
    runtimeConfig = config;

    var filePathEl = global.document.getElementById("file-path");
    var fileNameEl = global.document.getElementById("file-name");
    var titleEl = global.document.getElementById("share-title");
    var submitBtn = global.document.getElementById("submit-btn");
    if (!filePathEl || !submitBtn) return;

    var filePath = config.initialPath || "";
    var fileName = config.initialName || "";
    var fileSize = config.initialSize || 0;
    var queryValid = !config.requireQueryParams || (filePath && fileName);

    filePathEl.value = filePath;
    fileNameEl.value = fileName;
    filePathEl.disabled = !config.pathEditable && !!filePath;
    fileNameEl.disabled = !config.pathEditable && !!fileName;

    var titleDefault = fileName;
    var dotIdx = fileName.lastIndexOf(".");
    if (dotIdx > 0) titleDefault = fileName.substring(0, dotIdx);
    if (titleEl) titleEl.value = titleDefault;

    if (!queryValid) {
      showFieldError(config.missingParamsMessage);
      submitBtn.disabled = true;
      if (titleEl) titleEl.disabled = true;
      global.document.getElementById("price-yuan").disabled = true;
      global.document.getElementById("access-days").disabled = true;
    }

    // API Key 栏：Nextcloud 或同域 Server 隐藏
    var apiKeyBar = global.document.getElementById("api-key-bar");
    if (apiKeyBar) {
      if (config.authType === "ncCsrf" || config.authType === "none") {
        apiKeyBar.classList.add("hidden");
      } else {
        try {
          var apiOrigin = new URL(config.createUrl, global.location.origin).origin;
          if (apiOrigin === global.location.origin) apiKeyBar.classList.add("hidden");
        } catch (e) { /* keep */ }
      }
    }

    var adminLink = global.document.getElementById("admin-link");
    if (adminLink && config.adminLink) adminLink.href = config.adminLink;

    global.saveApiKey = function (val) {
      if (val && global.sessionStorage) global.sessionStorage.setItem("sg_api_key", val);
      else if (global.sessionStorage) global.sessionStorage.removeItem("sg_api_key");
    };

    var savedKey = global.sessionStorage && global.sessionStorage.getItem("sg_api_key");
    var keyInput = global.document.getElementById("api-key-input");
    if (savedKey && keyInput) keyInput.value = savedKey;

    function showFieldError(msg) {
      var el = global.document.getElementById("error-msg");
      if (!el) return;
      el.textContent = "❌ " + msg;
      el.style.display = "block";
      global.scrollTo({ top: 0, behavior: "smooth" });
    }

    global.showFieldError = showFieldError;

    global.handleSubmit = function () {
      filePath = filePathEl.value.trim();
      fileName = fileNameEl.value.trim();
      var title = titleEl ? titleEl.value.trim() : "";
      var priceYuan = parseFloat(global.document.getElementById("price-yuan").value);
      var days = parseInt(global.document.getElementById("access-days").value, 10);
      var expireDaysStr = global.document.getElementById("expire-days").value.trim();

      if (!filePath || !fileName || !title) { showFieldError("请填写文件路径、文件名和分享标题"); return; }
      if (!priceYuan || priceYuan <= 0) { showFieldError("价格必须大于 0"); return; }
      if (!days || days < 1) { showFieldError("授权天数至少为 1"); return; }

      global.document.getElementById("error-msg").style.display = "none";
      var loading = global.document.getElementById("submit-loading");
      submitBtn.disabled = true;
      if (loading) loading.classList.add("show");

      var body = {
        file_path: filePath,
        file_name: fileName,
        file_size: fileSize > 0 ? fileSize : undefined,
        storage_type: config.storageType,
        title: title,
        price: Math.round(priceYuan * 100),
        access_days: days,
      };
      if (expireDaysStr) body.share_expire_days = parseInt(expireDaysStr, 10);

      apiFetch(config.createUrl, { method: "POST", body: JSON.stringify(body) }, config.authType)
        .then(function (r) { return r.json(); })
        .then(function (result) {
          if (result.success) showSuccess(result, config);
          else {
            showFieldError(result.error || "创建失败");
            showToast(result.error || "创建失败", "error");
          }
        })
        .catch(function (err) {
          showFieldError("网络错误: " + err.message);
          showToast("网络错误", "error");
        })
        .finally(function () {
          submitBtn.disabled = false;
          if (loading) loading.classList.remove("show");
          submitBtn.innerHTML = "✨ 生成付费分享";
        });
    };

    function showSuccess(data, cfg) {
      global.document.getElementById("form-area").style.display = "none";
      var area = global.document.getElementById("success-area");
      var base = (cfg.publicBase || global.location.origin).replace(/\/+$/, "");
      var path = data.share_url || "/s/" + data.share_id;
      var fullUrl = /^https?:\/\//.test(path) ? path : base + path;

      area.style.display = "block";
      area.innerHTML =
        '<div class="success-box">' +
        '<div style="font-size:22px;">✅</div>' +
        '<div style="font-weight:bold;color:#155724;">分享创建成功</div>' +
        '<div class="short-url-wrap">' +
        '<input type="text" id="short-url-input" value="' + fullUrl.replace(/"/g, "&quot;") + '" readonly>' +
        '<button class="btn btn-outline" onclick="copyUrl()">📋 复制</button></div>' +
        '<div class="meta">💰 ¥' + (data.price / 100).toFixed(2) + " · 📅 " + data.access_days + " 天</div>" +
        '<div id="qr-container"></div>' +
        '<div class="hint">买家扫码支付后可下载</div>' +
        '<button class="btn btn-success" style="margin-top:10px;" onclick="resetForm()">🔄 再创建一个</button></div>';

      setTimeout(function () {
        if (!global.QRCode) return;
        global.QRCode.toDataURL(fullUrl, { width: 170, margin: 1 }, function (err, url) {
          var c = global.document.getElementById("qr-container");
          if (!c) return;
          c.innerHTML = err
            ? '<p style="font-size:12px;color:#999;">二维码加载失败</p>'
            : '<img src="' + url + '" style="width:170px;height:170px;" alt="QR">';
        });
      }, 50);
    }

    global.copyUrl = function () {
      var input = global.document.getElementById("short-url-input");
      if (!input) return;
      input.select();
      try {
        global.document.execCommand("copy");
        showToast("已复制");
      } catch (e) {
        showToast("复制失败", "error");
      }
    };

    global.resetForm = function () {
      global.document.getElementById("form-area").style.display = "block";
      global.document.getElementById("success-area").style.display = "none";
      global.document.getElementById("error-msg").style.display = "none";
      if (titleEl) titleEl.value = titleDefault;
      global.document.getElementById("price-yuan").value = "1.00";
      global.document.getElementById("access-days").value = "30";
      global.document.getElementById("expire-days").value = "";
    };

    if (config.fileInfoUrl && filePath && !fileSize) {
      apiFetch(config.fileInfoUrl + "?path=" + encodeURIComponent(filePath), {}, config.authType)
        .then(function (r) { return r.json(); })
        .catch(function () { return null; });
    }
  }

  global.ShareGateEmbedCreate = { init: init, serverDefaults: serverDefaults };

  if ("__SHAREGATE_EMBED_CONFIG" in global) {
    var boot = function () { init(global.__SHAREGATE_EMBED_CONFIG); };
    if (global.document.readyState === "loading") {
      global.document.addEventListener("DOMContentLoaded", boot);
    } else {
      boot();
    }
  }
})(typeof window !== "undefined" ? window : this);

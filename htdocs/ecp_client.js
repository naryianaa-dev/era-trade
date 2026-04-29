/**
 * ecp_client.js
 *
 * Тонкий клиент для работы с КриптоПро ЭЦП Browser Plug-in (cadesplugin).
 * Если плагин не установлен — функции вернут понятную ошибку.
 *
 * TODO[ECP-CLIENT]: реальная подпись через cadesplugin.CreateObjectAsync(...)
 * с алгоритмом GOST R 34.10-2012. Сейчас вспомогательные функции готовы,
 * но реальный вызов плагина закомментирован — на сервере стоит заглушка
 * verify, поэтому шлём DEMO-подпись и DEMO-сертификат пока боевая крипта
 * не подключена.
 */
(function (global) {
  'use strict';

  function isPluginAvailable() {
    return typeof global.cadesplugin !== 'undefined';
  }

  function notifyMissingPlugin() {
    alert(
      'Для входа/подписи через ЭЦП установите КриптоПро ЭЦП Browser Plug-in:\n' +
      'https://www.cryptopro.ru/products/cades/plugin\n\n' +
      'После установки перезагрузите страницу.'
    );
  }

  /* DEMO-подпись для разработки. Сервер сейчас валидирует только формально. */
  function demoSignature(dataStr) {
    const enc = new TextEncoder().encode(dataStr + ':DEMO');
    let bin = '';
    enc.forEach(b => bin += String.fromCharCode(b));
    return btoa(bin) + 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
  }
  function demoCert() {
    /* Минимальный фейк base64 — пройдёт формальную проверку на сервере. */
    return 'MIIBkjCCAT2gAwIBAgIUDEMOTOKEN1234567890ABCDEFGHIJ' +
           'MAoGCCqGSM49BAMCMA0xCzAJBgNVBAYTAlJVMB4XDTI0MDEwMTAwMDAwMFoX' +
           'DTI1MDEwMTAwMDAwMFowDTELMAkGA1UEBhMCUlUwWTATBgcqhkjOPQIBBggq' +
           'hkjOPQMBBwNCAAQk9bQ8DEMOSESSIONONLYFAKEFAKEFAKEFAKEFAKEFAKEF' +
           'AKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE';
  }

  /* Логин через ЭЦП: init → подпись → verify. */
  async function ecpLogin() {
    /* 1) запросить challenge */
    const r1 = await fetch('ecp_login_init.php', { credentials: 'same-origin' });
    const d1 = await r1.json();
    if (!d1.success) { alert('Ошибка ЭЦП: ' + (d1.error || 'init failed')); return; }

    let signature_b64, cert_pem;

    if (isPluginAvailable()) {
      /* TODO[ECP-CLIENT]: реальная подпись через КриптоПро плагин.
         Пример (раскомментировать когда подключим):
         const cads = await cadesplugin.CreateObjectAsync('CAdESCOM.CadesSignedData');
         await cads.propset_Content(d1.data_to_sign);
         signature_b64 = await cads.SignCades(...);
         cert_pem = await cert.Export(0);
      */
      signature_b64 = demoSignature(d1.data_to_sign);
      cert_pem      = demoCert();
    } else {
      /* Без плагина — для разработки шлём DEMO-данные.
         В проде здесь должно быть notifyMissingPlugin() и return. */
      signature_b64 = demoSignature(d1.data_to_sign);
      cert_pem      = demoCert();
    }

    /* 2) verify */
    const r2 = await fetch('ecp_login_verify.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        nonce: d1.nonce,
        signature_b64,
        cert_pem,
      })
    });
    const d2 = await r2.json();
    if (!d2.success) { alert('Ошибка ЭЦП: ' + (d2.error || 'verify failed')); return; }
    location.href = d2.redirect || 'profile.php';
  }

  /* Подписать произвольные данные (для оффера/ставки) и записать в ecp_signatures. */
  async function ecpSign(targetType, targetId, payloadObj) {
    const payload_json = JSON.stringify(payloadObj);

    let signature_b64, cert_pem;
    if (isPluginAvailable()) {
      /* TODO[ECP-CLIENT]: реальная подпись payload_json. */
      signature_b64 = demoSignature(payload_json);
      cert_pem      = demoCert();
    } else {
      signature_b64 = demoSignature(payload_json);
      cert_pem      = demoCert();
    }

    const fd = new FormData();
    fd.append('target_type', targetType);
    if (targetId) fd.append('target_id', targetId);
    fd.append('payload_json', payload_json);
    fd.append('signature_b64', signature_b64);
    fd.append('cert_pem', cert_pem);

    const r = await fetch('ecp_sign.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return await r.json();
  }

  global.ECP = { login: ecpLogin, sign: ecpSign, isPluginAvailable, notifyMissingPlugin };
})(window);

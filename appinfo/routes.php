<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'dashboard#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'dashboard#summary', 'url' => '/api/dashboard/summary', 'verb' => 'GET'],
		['name' => 'dashboard#account', 'url' => '/api/dashboard/account', 'verb' => 'GET'],
		['name' => 'dashboard#stats', 'url' => '/api/dashboard/stats', 'verb' => 'GET'],
		['name' => 'dashboard#list', 'url' => '/api/dashboard/shares', 'verb' => 'GET'],
		['name' => 'files#publicLinks', 'url' => '/api/files/public-links', 'verb' => 'GET'],

		['name' => 'share#createEmbed', 'url' => '/embed/create', 'verb' => 'GET'],
		['name' => 'share#createShare', 'url' => '/share/create', 'verb' => 'POST'],
		['name' => 'share#settings', 'url' => '/share/{shareId}/settings', 'verb' => 'GET'],
		['name' => 'share#getShareSettings', 'url' => '/api/share/{shareId}', 'verb' => 'GET'],
		['name' => 'share#updateShare', 'url' => '/share/{shareId}', 'verb' => 'PUT'],

		['name' => 'share#view', 'url' => '/s/{shareId}', 'verb' => 'GET'],
		['name' => 'share#getShareInfo', 'url' => '/s/{shareId}/info', 'verb' => 'GET'],
		['name' => 'share#download', 'url' => '/s/{shareId}/verify', 'verb' => 'POST'],
		['name' => 'share#disable', 'url' => '/share/{shareId}/disable', 'verb' => 'PATCH'],
		['name' => 'share#downloadFile', 'url' => '/s/{shareId}/download', 'verb' => 'GET'],
		['name' => 'share#saveToCloud', 'url' => '/s/{shareId}/save-to-cloud', 'verb' => 'POST'],

		['name' => 'payment#create', 'url' => '/payment/create', 'verb' => 'POST'],
		['name' => 'payment#qrImage', 'url' => '/payment/qr/{orderId}', 'verb' => 'GET'],
		['name' => 'payment#check', 'url' => '/payment/check/{shareId}', 'verb' => 'GET'],
		['name' => 'payment#verify', 'url' => '/payment/verify', 'verb' => 'POST'],
		['name' => 'payment#webhook', 'url' => '/payment/webhook', 'verb' => 'POST'],
		['name' => 'payment#status', 'url' => '/payment/status/{orderId}', 'verb' => 'GET'],
		['name' => 'payment#mockPay', 'url' => '/pay/mock/{orderId}', 'verb' => 'GET'],
		['name' => 'payment#notifyAlipay', 'url' => '/payment/notify/alipay_f2f', 'verb' => 'POST'],
		['name' => 'payment#notifyAlipayHealth', 'url' => '/payment/notify/alipay_f2f', 'verb' => 'GET'],
		['name' => 'payment#manualConfirm', 'url' => '/payment/manual-confirm', 'verb' => 'POST'],

		['name' => 'admin#paymentConfig', 'url' => '/admin/payment-config', 'verb' => 'GET'],
		['name' => 'admin#savePaymentConfig', 'url' => '/admin/payment-config', 'verb' => 'POST'],
		['name' => 'admin#shares', 'url' => '/admin/shares', 'verb' => 'GET'],
		['name' => 'admin#payments', 'url' => '/admin/payments', 'verb' => 'GET'],
		['name' => 'admin#stats', 'url' => '/admin/stats', 'verb' => 'GET'],
		['name' => 'admin#settings', 'url' => '/admin/settings', 'verb' => 'POST'],
	],
];

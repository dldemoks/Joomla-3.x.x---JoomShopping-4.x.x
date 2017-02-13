<?php

defined('_JEXEC') or die();

class pm_payeer extends PaymentRoot{
    
    function showPaymentForm($params, $pmconfigs)
	{
        include(dirname(__FILE__) . '/paymentform.php');
    }

	function showAdminFormParams($params)
	{
		$jmlThisDocument = &JFactory::getDocument();
		$pm_method = $this->getPmMethod();
		$hosturl = JURI::root();
		
		if ($jmlThisDocument->language == 'en-gb')
		{
			include(JPATH_SITE . '/administrator/components/com_jshopping/lang/en-GB_payeer.php');
		}
		else
		{
			include(JPATH_SITE . '/administrator/components/com_jshopping/lang/ru-RU_payeer.php');
		}
		
		$array_params = array(
			'merchant_url',
			'merchant_id',
			'secret_key',
			'log_file',
			'ip_filter',
			'email_err',
			'transaction_end_status',
			'transaction_pending_status',
			'transaction_failed_status',
			'success_url',
			'fail_url',
			'status_url'
		);
		
		if (!isset($params['merchant_url']) || empty($params['merchant_url']))
		{
			$params['merchant_url'] = 'https://payeer.com/merchant/';
		}
		
		$params['success_url'] = $hosturl . 'index.php?option=com_jshopping&controller=checkout&task=step7&act=return&js_paymentclass=' . $pm_method->payment_class;
		$params['fail_url'] = $hosturl . 'index.php?option=com_jshopping&controller=checkout&task=step7&act=cancel&js_paymentclass=' . $pm_method->payment_class;
		$params['status_url'] = $hosturl . 'index.php?option=com_jshopping&controller=checkout&task=step7&act=notify&js_paymentclass=' . $pm_method->payment_class;

		foreach ($array_params as $key)
		{
			if (!isset($params[$key]))
			{
				$params[$key] = '';
			}
		}

		$orders = JSFactory::getModel('orders', 'JshoppingModel');
		include(dirname(__FILE__) . '/adminparamsform.php');
	}

	function checkTransaction($pmconfigs, $order, $act)
	{
        $jshopConfig = JSFactory::getConfig();
		$post = JFactory::getApplication()->input->post->getArray();
		$jmlThisDocument = &JFactory::getDocument();
		
		if (isset($post['m_operation_id']) && isset($post['m_sign']))
		{
			$err = false;
			$message = '';
			
			if ($jmlThisDocument->language == 'en-gb')
			{
				include(JPATH_SITE . '/administrator/components/com_jshopping/lang/en-GB_payeer.php');
			}
			else
			{
				include(JPATH_SITE . '/administrator/components/com_jshopping/lang/ru-RU_payeer.php');
			}
			
			// запись логов
			
			$log_text = 
				"--------------------------------------------------------\n" .
				"operation id       " . $post['m_operation_id'] . "\n" .
				"operation ps       " . $post['m_operation_ps'] . "\n" .
				"operation date     " . $post['m_operation_date'] . "\n" .
				"operation pay date " . $post['m_operation_pay_date'] . "\n" .
				"shop               " . $post['m_shop'] . "\n" .
				"order id           " . $post['m_orderid'] . "\n" .
				"amount             " . $post['m_amount'] . "\n" .
				"currency           " . $post['m_curr'] . "\n" .
				"description        " . base64_decode($post['m_desc']) . "\n" .
				"status             " . $post['m_status'] . "\n" .
				"sign               " . $post['m_sign'] . "\n\n";
			
			$log_file = $pmconfigs['log_file'];
			
			if (!empty($log_file))
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
			}
			
			// проверка цифровой подписи и ip

			$sign_hash = strtoupper(hash('sha256', implode(":", array(
				$post['m_operation_id'],
				$post['m_operation_ps'],
				$post['m_operation_date'],
				$post['m_operation_pay_date'],
				$post['m_shop'],
				$post['m_orderid'],
				$post['m_amount'],
				$post['m_curr'],
				$post['m_desc'],
				$post['m_status'],
				$pmconfigs['secret_key']
			))));
			
			$valid_ip = true;
			$sIP = str_replace(' ', '', $pmconfigs['ip_filter']);
			
			if (!empty($sIP))
			{
				$arrIP = explode('.', $_SERVER['REMOTE_ADDR']);
				if (!preg_match('/(^|,)(' . $arrIP[0] . '|\*{1})(\.)' .
				'(' . $arrIP[1] . '|\*{1})(\.)' .
				'(' . $arrIP[2] . '|\*{1})(\.)' .
				'(' . $arrIP[3] . '|\*{1})($|,)/', $sIP))
				{
					$valid_ip = false;
				}
			}
			
			if (!$valid_ip)
			{
				$message .= _JSHOP_PAYEER_MSG_NOT_VALID_IP . "\n" .
				_JSHOP_PAYEER_MSG_VALID_IP . $sIP . "\n" .
				_JSHOP_PAYEER_MSG_THIS_IP . $_SERVER['REMOTE_ADDR'] . "\n";
				$err = true;
			}

			if ($post['m_sign'] != $sign_hash)
			{
				$message .= _JSHOP_PAYEER_MSG_HASHES_NOT_EQUAL . "\n";
				$err = true;
			}

			if (!$err)
			{
				$order_curr = strtoupper($order->currency_code_iso);
				$order_curr = ($order_curr == 'RUR') ? 'RUB' : $order_curr;
				$order_amount = number_format($order->order_total, 2, '.', '');
				
				// проверка суммы и валюты
			
				if ($post['m_amount'] != $order_amount)
				{
					$message .= _JSHOP_PAYEER_MSG_WRONG_AMOUNT . "\n";
					$err = true;
				}

				if ($post['m_curr'] != $order_curr)
				{
					$message .= _JSHOP_PAYEER_MSG_WRONG_CURRENCY . "\n";
					$err = true;
				}

				// проверка статуса
				
				if (!$err)
				{
					switch ($post['m_status'])
					{
						case 'success':
							
							echo $post['m_orderid'] . '|success';
							
							if ($order->order_status != $pmconfigs['transaction_end_status'])
							{
								return array(1, $post['m_orderid']);
							}
							else
							{
								return false;
							}
							
							break;
							
						default:

							$message .= _JSHOP_PAYEER_MSG_STATUS_FAIL . "\n";
							$err = true;
							
							break;
					}
				}
			}
			
			if ($err)
			{
				$to = $pmconfigs['email_err'];

				if (!empty($to))
				{
					$message = _JSHOP_PAYEER_MSG_ERR_REASONS . "\n\n" . $message . "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" . 
					"Content-type: text/plain; charset=utf-8 \r\n";
					mail($to, _JSHOP_PAYEER_MSG_SUBJECT, $message, $headers);
				}
				
				echo $post['m_orderid'] . '|error';
				return array(0, $post['m_orderid']);
			}
		}
	}

	function showEndForm($pmconfigs, $order)
	{
		$jshopConfig = JSFactory::getConfig();
		
		$m_url = $pmconfigs['merchant_url'];
		$m_shop = $pmconfigs['merchant_id'];
		$m_orderid = $order->order_id;
		$m_amount = number_format($order->order_total, 2, '.', '');
		$m_curr = strtoupper($order->currency_code_iso);
		$m_curr = ($m_curr == 'RUR') ? 'RUB' : $m_curr;
		$m_desc = base64_encode($order->order_add_info);
		$m_key = $pmconfigs['secret_key'];

		if (empty($_GET['lang']))
		{
            $m_lang = 'ru';
		}
        else
		{
            $m_lang = $_GET['lang'];
		}
		
		$arHash = array(
			$m_shop,
			$m_orderid,
			$m_amount,
			$m_curr,
			$m_desc,
			$m_key
		);
		
		$sign = strtoupper(hash('sha256', implode(':', $arHash)));
?>
		<html>
			<head>
				<meta http-equiv="content-type" content="text/html; charset=UTF-8" />          
			</head>        
			<body>
				<form action="<?php echo $m_url; ?>" id="paymentform" name="paymentform" method="GET">
					<input type="hidden" name="m_shop" value="<?php echo $m_shop; ?>">
					<input type="hidden" name="m_orderid" value="<?php echo $m_orderid; ?>">
					<input type="hidden" name="m_amount" value="<?php echo $m_amount; ?>">
					<input type="hidden" name="m_curr" value="<?php echo $m_curr; ?>">
					<input type="hidden" name="m_desc" value="<?php echo $m_desc; ?>">
					<input type="hidden" name="m_sign" value="<?php echo $sign; ?>">
					<input type="hidden" name="lang" value="<?php echo $m_lang; ?>">
				</form>
				<?php print _JSHOP_REDIRECT_TO_PAYMENT_PAGE ?>
				<br>
				<script type="text/javascript">document.getElementById('paymentform').submit();</script>
			</body>
        </html>
<?php
        die();
	}
    
    function getUrlParams($pmconfigs)
	{
        $params = array(); 
        $params['order_id'] = JFactory::getApplication()->input->getInt('m_orderid');
        $params['hash'] = "";
        $params['checkHash'] = 0;
        $params['checkReturnParams'] = $pmconfigs['checkdatareturn'];
		return $params;
    }
}
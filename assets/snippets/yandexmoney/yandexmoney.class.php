<?php
if (!defined('YANDEXMONEY_VERSION')) define('YANDEXMONEY_VERSION', '1.4.2');
if(!function_exists('YandexMoneyForm')){
	function YandexMoneyForm(&$fields){
	  global $modx, $vMsg;
	  
	  if ($_POST){
			$dbname = $modx->db->config['dbase']; //имя базы данных
			$dbprefix = $modx->db->config['table_prefix']; //префикс таблиц
			$mod_table = $dbprefix."yandexmoney"; //таблица модуля
			
			$data_query = $modx->db->select("*", $mod_table, "", "id ASC", ""); 
			$row = $modx->db->getRow($data_query);
			$config = unserialize($row['config']);
			
			$ym = new Yandexmoney($config);

			$ym->pay_method = $_POST['payment'];
		  	if (isset($_POST['phone'])) $ym->phone = $_POST['phone'];
		  	if (isset($_POST['email'])) $ym->email = $_POST['email'];

			if ($ym->checkPayMethod()) {
				
				$orderId = (int)$fields['orderID'];
				
				$order_query = $modx->db->select("*", $modx->getFullTableName('manager_shopkeeper'), 'id = ' . $orderId, "", "");
				$order = $modx->db->getRow($order_query, 'both');

				$data = @unserialize($order['short_txt']);
				$price = floatval(str_replace(',', '.', $order['price']));

				$ym->orderId = $orderId;
				$ym->orderTotal = $price;
				$ym->comment = $data['message'];
				$userLoggedIn = $modx->userLoggedIn();
				$ym->userId = $userLoggedIn!==false ? $userLoggedIn['id'] : 0;
				echo $ym->createFormHtml();
				exit;
			}
	  }
	  
	  return true;
	}
}


if(!function_exists('YandexMoneyValidate')){
	function YandexMoneyValidate(&$fields){
	  global $modx;
	  if (!empty($fields)){
		return true;
	  }else{
		return false;
	  }
	}
}



/**
 * YandexMoney for MODX Evo
 *
 * Payment
 *
 * @author YandexMoney
 * @package yandexmoney
 */

if (!class_exists('Yandexmoney')){
class Yandexmoney {
	private $paymode;

	public $email = false;
	public $phone = false;

	public $test_mode;
	public $org_mode;
	public $status;

	public $orderId;
	public $orderTotal;
	public $userId;

	public $successUrl;
	public $failUrl;

	public $reciver;
	public $formcomment;
	public $short_dest;
	public $writable_targets = 'false';
	public $comment_needed = 'true';
	public $label;
	public $quickpay_form = 'shop';
	public $payment_type = '';
	public $targets;
	public $sum;
	public $comment;
	public $need_fio = 'true';
	public $need_email = 'true';
	public $need_phone = 'true';
	public $need_address = 'true';

	public $shopid;
	public $scid;
	public $account;
	public $password;

	public $method_ym;
	public $method_cards;
	public $method_cash;
	public $method_mobile;
	public $method_wm;
	public $method_ab;
	public $method_sb;
	public $method_ma;
	public $method_pb;

	public $pay_method;
    
    function __construct($config = array()) {
		
		$this->org_mode = ($config['mode'] >= 2);
		$this->test_mode = ($config['testmode'] == 1);
		$this->shopid = ($config['testmode'] == 1);
		$this->paymode = (bool) ($config['mode'] == 3);

		
		if (isset($config) && is_array($config)){
			foreach ($config as $k=>$v){
				$this->$k = $v;
			}
		}
    }

	public function getFormUrl(){
		$demo = ($this->test_mode)?'demo':'';
		$mode = ($this->org_mode)?'/eshop.xml':'/quickpay/confirm.xml';
		return 'https://'.$demo.'money.yandex.ru'.$mode;
	}

	public function checkPayMethod(){
		return (in_array($this->pay_method, array('PC','AC','MC','GP','WM','AB','SB','MA','PB','QW','QP')) || $this->paymode);
	}

	public function getSelectHtml(){
		if ((int)$this->status === 0)	return '';
		if ($this->paymode) return "<option value=''>Яндекс.Касса (банковские карты, электронные деньги и другое)</option>";
		$list_methods=array(
			'ym'=>array('PC'=>'Оплата из кошелька в Яндекс.Деньгах'),
			'cards'=>array('AC'=>'Оплата с произвольной банковской карты'),
			'cash'=>array('GP'=>'Оплата наличными через кассы и терминалы'),
			'mobile'=>array('MC'=>'Платеж со счета мобильного телефона'),
			'ab'=>array('AB'=>'Оплата через Альфа-Клик'),
			'sb'=>array('SB'=>'Оплата через Сбербанк: оплата по SMS или Сбербанк Онлайн'),
			'wm'=>array('WM'=>'Оплата из кошелька в системе WebMoney'),
			'ma'=>array('MA'=>'Оплата через MasterPass'),
			'pb'=>array('PB'=>'Оплата через интернет-банк Промсвязьбанка'),
			'qw'=>array('QW'=>'Оплата через QIWI Wallet'),
			'qp'=>array('QP'=>'Оплата через доверительный платеж (Куппи.ру)')
		);
		$output='';
		foreach ($list_methods as $long_name=>$method_desc){
			$by_default=(in_array($long_name, array('ym','cards')))?true:$this->org_mode;
			if ($this->{'method_'.$long_name} == 1 && $by_default) {
				$output .= '<option value="'.key($method_desc).'"';
				if ($this->pay_method == key($method_desc)) $output.=' selected ';
				$output .= '>'.$method_desc[key($method_desc)].'</option>';
			}
		}
		return $output;
	}

	public function createFormHtml(){
		global $modx;
		$site_url = $modx->config['site_url'];
		$payType = ($this->paymode)?'':$this->pay_method;
		$addInfo =($this->email!==false)?'<input type="hidden" name="cps_email" value="'.$this->email.'" >':'';
		$addInfo .= ($this->phone!==false)?'<input type="hidden" name="cps_phone" value="'.$this->phone.'" >':'';
		if ($this->org_mode){
			$html = '
				<form method="POST" action="'.$this->getFormUrl().'"  id="paymentform" name = "paymentform">
				   <input type="hidden" name="paymentType" value="'.$payType.'" />
				   <input type="hidden" name="shopid" value="'.$this->shopid.'">
				   <input type="hidden" name="scid" value="'.$this->scid.'">
				   <input type="hidden" name="orderNumber" value="'.$this->orderId.'">
				   <input type="hidden" name="sum" value="'.$this->orderTotal.'" data-type="number" >
				   <input type="hidden" name="customerNumber" value="'.$this->userId.'" >'.
					$addInfo.
					'<input type="hidden" name="shopSuccessUrl" value="'.$site_url.'assets/snippets/yandexmoney/callback.php?success=1">
					<input type="hidden" name="shopFailUrl" value="'.$site_url.'assets/snippets/yandexmoney/callback.php?fail=1">
				   <input type="hidden" name="cms_name" value="modxevo" >	
				</form>';
		}else{
			$html = '<form method="POST" action="'.$this->getFormUrl().'"  id="paymentform" name = "paymentform">
					   <input type="hidden" name="receiver" value="'.$this->account.'">
					   <input type="hidden" name="formcomment" value="Order '.$this->orderId.'">
					   <input type="hidden" name="short-dest" value="Order '.$this->orderId.'">
					   <input type="hidden" name="writable-targets" value="'.$this->writable_targets.'">
					   <input type="hidden" name="comment-needed" value="'.$this->comment_needed.'">
					   <input type="hidden" name="label" value="'.$this->orderId.'">
					   <input type="hidden" name="quickpay-form" value="'.$this->quickpay_form.'">
					   <input type="hidden" name="paymentType" value="'.$this->pay_method.'">
					   <input type="hidden" name="targets" value="Заказ '.$this->orderId.'">
					   <input type="hidden" name="sum" value="'.$this->orderTotal.'" data-type="number" >
					   <input type="hidden" name="comment" value="'.$this->comment.'" >
					   <input type="hidden" name="need-fio" value="'.$this->need_fio.'">
					   <input type="hidden" name="need-email" value="'.$this->need_email.'" >
					   <input type="hidden" name="need-phone" value="'.$this->need_phone.'">
					   <input type="hidden" name="need-address" value="'.$this->need_address.'">
						<input type="hidden" name="successUrl" value="'.$site_url.'assets/snippets/yandexmoney/callback.php?success=1"> 
					</form>';
		}
		$html .= '<script type="text/javascript">
						document.getElementById("paymentform").submit();
					</script>';
		echo $html;
		exit;
	}


	public function checkSign($callbackParams){
		if ($this->org_mode){
			$string = $callbackParams['action'].';'.$callbackParams['orderSumAmount'].';'.$callbackParams['orderSumCurrencyPaycash'].';'.$callbackParams['orderSumBankPaycash'].';'.$callbackParams['shopId'].';'.$callbackParams['invoiceId'].';'.$callbackParams['customerNumber'].';'.$this->password;
			$md5 = strtoupper(md5($string));
			return ($callbackParams['md5']==$md5);
		}else{
			$string = $callbackParams['notification_type'].'&'.$callbackParams['operation_id'].'&'.$callbackParams['amount'].'&'.$callbackParams['currency'].'&'.$callbackParams['datetime'].'&'.$callbackParams['sender'].'&'.$callbackParams['codepro'].'&'.$this->password.'&'.$callbackParams['label'];
			$check = (sha1($string) == $callbackParams['sha1_hash']);
			if (!$check){
				header('HTTP/1.0 401 Unauthorized');
				return false;
			}
			return true;
		}
	}

	public function sendCode($callbackParams, $code){
		header("Content-type: text/xml; charset=utf-8");
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
			<'.$callbackParams['action'].'Response performedDatetime="'.date("c").'" code="'.$code.'" invoiceId="'.$callbackParams['invoiceId'].'" shopId="'.$this->shopid.'"/>';
		echo $xml;
	}

	/* оплачивает заказ */
	public function ProcessResult()
	{
	    global $modx;
		$callbackParams = $_POST;
		$order_id = false;

		if ($this->checkSign($callbackParams)){
			if ($this->org_mode){

                $order_query = $modx->db->select("*", $modx->getFullTableName('manager_shopkeeper'), 'id = ' . (int) $callbackParams["orderNumber"], "", "");
                $order = $modx->db->getRow($order_query, 'both');
                $price = floatval(str_replace(',', '.', $order['price']));

				if ($callbackParams['action'] == 'paymentAviso'){
					$order_id = (int)$callbackParams["orderNumber"];
				}elseif(number_format($callbackParams['orderSumAmount'], 2) != number_format($price, 2)){
                    $this->sendCode($callbackParams, 100);
                }
			}else{
				$order_id = (int)$callbackParams["label"];
			}
			$this->sendCode($callbackParams, 0);
		}else{
			$this->sendCode($callbackParams, 1);
		}
		return $order_id;
	}

	public static function adminHeadHtml($theme)
	{
		return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
				<html xmlns="http://www.w3.org/1999/xhtml"  lang="en" xml:lang="en">
				<head>
				  <link rel="stylesheet" type="text/css" href="media/style/'.$theme.'/style.css" />
				  <script src="https://yastatic.net/jquery/1.9.1/jquery.min.js"></script>
				</head>
				<body>';
	}
	
	public static function adminInstallHtml()
	{
		$html = '<br/><h1>YandexMoney Payment Module</h1>'
			  . 'Лицензионный договор.
			  <p>Любое использование Вами программы означает полное и безоговорочное принятие Вами условий лицензионного договора, размещенного по адресу <a href="https://money.yandex.ru/doc.xml?id=527132">https://money.yandex.ru/doc.xml?id=527132</a> (далее – «Лицензионный договор»). Если Вы не принимаете условия Лицензионного договора в полном объёме, Вы не имеете права использовать программу в каких-либо целях.</p>'
			  . '<form action="" method="POST">'
			  . '<input type="hidden" name="action" value="install" />'
			  . '<input type="submit" value="Установить">'
			  . '</form>';
		return $html;
	}

	public static function isInstalled($dbname, $mod_table)
	{
		global $modx;
		$c = $modx->db->getRecordCount($modx->db->query($sql = "show tables from $dbname like '$mod_table'"));
		return ($c > 0);
	}

	public function adminModuleHtml()
	{
		global $modx;
		$rb_base_url = $modx->config['rb_base_url'];
		$site_url = $modx->config['site_url'];

		$html = '<div class="box">
	
    <div class="heading">
      <h1>Яндекс.Деньги</h1>
     
    </div>
    <div class="content">
	
      <form action="" method="post" enctype="multipart/form-data" id="form">
		
        <table class="form">
		<tbody>
		<tr>
			<td>Версия модуля</td>
			<td>'.YANDEXMONEY_VERSION.'</td>
		</tr>
		<tr>
            <td>Статус:</td>
            <td>
				<select name="config[status]">
                 <option value="1" '.(($this->status==1 ? 'selected' : '')).'>Включено</option>
				 <option value="0"'.(($this->status==0 ? 'selected' : '')).'>Выключено</option>
            </select>
			 </td>
          </tr>
          <tr class="individ">
			<td></td>
			<td><p>Если у вас нет аккаунта в Яндекс-Деньги, то следует зарегистрироваться тут - <a href="https://money.yandex.ru/">https://money.yandex.ru/</a></p><p><b>ВАЖНО!</b> Вам нужно будет указать ссылку для приема HTTP уведомлений здесь - <a href="https://sp-money.yandex.ru/myservices/online.xml" target="_blank">https://sp-money.yandex.ru/myservices/online.xml</a></p></td>
		 </tr>

		  <tr class="org" style="display: none;">
			<td></td>
			<td><p>Для работы с модулем необходимо <a href="https://money.yandex.ru/joinups/">подключить магазин к Яндек.Кассе</a>. После подключения вы получите параметры для приема платежей (идентификатор магазина — shopId и номер витрины — scid).</p></td>
		 </tr>
		 <tr>
            <td><span class="required">*</span> Использовать в тестовом режиме?</td>
            <td>
				<input id="testmode1" type="radio" name="config[testmode]" value="1" '.(($this->testmode==1 ? 'checked' : '')).'>
				<label for="testmode1" style="text-align: left;" >Да</label>
				
				<input id="testmode2" type="radio" name="config[testmode]" value="0" '.(($this->testmode==0 ? 'checked' : '')).'>
				<label for="testmode2" style="text-align: left;">Нет</label>
			</td>
          </tr>
		  
		  <tr>
            <td><span class="required">*</span> Статус:</td>
            <td>
				<select name="config[mode]" id="yandexmoney_mode" onchange="yandex_validate_mode();">
					<option value="1" '.(($this->mode==1 ? 'selected' : '')).'>Физическое лицо</option>
					<option value="2" '.(($this->mode==2 ? 'selected' : '')).'>Юридическое лицо (выбор оплаты на стороне магазина)</option>
					<option value="3" '.(($this->mode==3 ? 'selected' : '')).'>Юридическое лицо (выбор оплаты на стороне Яндекс.Кассы)</option>
				</select>
			</td>
          </tr>
		   <tr class="select-w">
            <td><span class="required">*</span> Укажите необходимые способы оплаты:</td>
            <td>
				<input type="checkbox" name="config[method_ym]" value="1" id="ym_method_1" '.(($this->method_ym==1 ? 'checked' : '')).'>
				<label for="ym_method_1" style="width: 280px; text-align: left;">Кошелек Яндекс.Деньги</label>
				<br>
				<input type="checkbox" name="config[method_cards]" value="1" id="ym_method_2" '.(($this->method_cards==1 ? 'checked' : '')).'>
				<label for="ym_method_2" style="width: 280px; text-align: left;">Банковская карта</label>
				<br>
				<div class="org" style="display:none;">
					<input type="checkbox" name="config[method_cash]" value="1" id="ym_method_3" '.(($this->method_cash==1 ? 'checked' : '')).'><label for="ym_method_3" style="width: 280px; text-align: left;">Наличными через кассы и терминалы</label> <br>
					<input type="checkbox" name="config[method_mobile]" value="1" id="ym_method_4" '.(($this->method_mobile==1 ? 'checked' : '')).'><label for="ym_method_4" style="width: 280px; text-align: left;">Счет мобильного телефона</label> <br>
					<input type="checkbox" name="config[method_wm]" value="1" id="ym_method_5" '.(($this->method_wm==1 ? 'checked' : '')).'><label for="ym_method_5" style="width: 280px;text-align: left;">Кошелек WebMoney</label> <br>
					<input type="checkbox" name="config[method_ab]" value="1" id="ym_method_6" '.(($this->method_ab==1 ? 'checked' : '')).'><label for="ym_method_6" style="width: 280px;text-align: left;">Альфа-Клик</label> <br>
					<input type="checkbox" name="config[method_sb]" value="1" id="ym_method_7" '.(($this->method_sb==1 ? 'checked' : '')).'><label for="ym_method_7" style="width: 280px;text-align: left;">Сбербанк: оплата по SMS или Сбербанк Онлайн</label> <br>
					<input type="checkbox" name="config[method_ma]" value="1" id="ym_method_8" '.(($this->method_ma==1 ? 'checked' : '')).'><label for="ym_method_8" style="width: 280px;text-align: left;">MasterPass</label> <br>
					<input type="checkbox" name="config[method_pb]" value="1" id="ym_method_9" '.(($this->method_pb==1 ? 'checked' : '')).'><label for="ym_method_9" style="width: 280px;text-align: left;">Интернет-банк Промсвязьбанка</label> <br>
					<input type="checkbox" name="config[method_qw]" value="1" id="ym_method_10" '.(($this->method_qw==1 ? 'checked' : '')).'><label for="ym_method_10" style="width: 280px;text-align: left;">QIWI Wallet</label> <br>
					<input type="checkbox" name="config[method_qp]" value="1" id="ym_method_11" '.(($this->method_qp==1 ? 'checked' : '')).'><label for="ym_method_11" style="width: 280px;text-align: left;">Доверительный платеж (Куппи.ру)</label> <br>
				</div>
			 </td>
          </tr>
		 <tr  class="select-wo">
		 </tr>
		  <tr class="individ">
			<td></td>
			<td>
				Параметры для заполнения в личном кабинете:<br>
				<table style="border: 1px black solid;">
				  <tbody><tr>
						<td style="border: 1px black solid; padding: 5px;">Название параметра</td>
						<td style="border: 1px black solid; padding: 5px;">Значение</td>
				  </tr>
				  <tr>
						<td style="border: 1px black solid; padding: 5px;">Адрес приема HTTP уведомлений</td>
						<td style="border: 1px black solid; padding: 5px;">'.$site_url.'assets/snippets/yandexmoney/callback.php</td>
				   </tr>
				</tbody></table>
			</td>
		  </tr>

		   <tr class="org" style="display: none;">
			<td></td>
			<td>
				Параметры для заполнения в личном кабинете:<br>
				<table style="border: 1px black solid;">
				  <tbody><tr>
						<td style="border: 1px black solid; padding: 5px;">Название параметра</td>
						<td style="border: 1px black solid; padding: 5px;">Значение</td>
				  </tr>
				   <tr>
						<td style="border: 1px black solid; padding: 5px;">checkUrl/avisoUrl</td>
						<td style="border: 1px black solid; padding: 5px;">'.str_replace("http://","https://",$site_url).'assets/snippets/yandexmoney/callback.php</td>
				   </tr>
				   <tr>
						<td style="border: 1px black solid; padding: 5px;">successURL/failURL</td>
						<td style="border: 1px black solid; padding: 5px;">Динамический</td>
				   </tr>
				</tbody></table>
			</td>
		  </tr>

		  <tr class="individ">
            <td><span class="required">*</span> Номер кошелька Яндекс:</td>
            <td><input type="text" name="config[account]" value="'.$this->account.'">
              </td>
          </tr>
          <tr class="org" style="display: none;">
            <td><span class="required">*</span> Идентификатор вашего магазина в Яндекс.Деньгах (ShopID):</td>
            <td><input type="text" name="config[shopid]" value="'.$this->shopid.'">
              </td>
          </tr>
          <tr class="org" style="display: none;">
            <td><span class="required">*</span> Идентификатор витрины вашего магазина в Яндекс.Деньгах (scid):</td>
            <td><input type="text" name="config[scid]" value="'.$this->scid.'">
              </td>
          </tr>
          <tr>
            <td><span class="required">*</span> Секретное слово (shopPassword) для обмена сообщениями:</td>
            <td><input type="text" name="config[password]" value="'.$this->password.'">
              </td>
          </tr>
        </tbody></table>
		<br/>
		<input type="submit" value="Сохранить" />
		<input type="hidden" name="action" value="save" />
      </form>
    </div>
  </div>
<script type="text/javascript">
		function yandex_validate_mode(){
			var yandex_mode = $("#yandexmoney_mode").val();
			if (yandex_mode == 1){
				$(".individ").show();
				$(".org").hide();
				$(".select-w").show();
				$(".select-wo").hide();
			}else {
				$(".individ").hide();
				$(".org").show();
				if(yandex_mode == 2){
					$(".select-w").show();
					$(".select-wo").hide();
				}else{
					$(".select-w").hide();
					$(".select-wo").show();
				}
			}
		}
		$( document ).ready(function() {
			yandex_validate_mode();
		});
</script>';
		return $html;
	}

}
class yamoney_statistics {
	public function __construct($config){
		$this->send($config);
	}

	private function send($config)
	{
		global $modx;
		$array = array(
			'url' => $modx->config['site_url'],
			'cms' => 'modxevo',
			'version' => $modx->config['settings_version'],
			'ver_mod' => YANDEXMONEY_VERSION,
			'yacms' => false,
			'email' => $modx->config['emailsender'],
			'shopid' => $config['shopid'],
			'settings' => array(
				'kassa_epl' => (bool)($config['mode']==3),
				'kassa' => (bool)($config['mode']>=2),
				'p2p'=> (bool)($config['mode']!=2)
			)
		);
		$array_crypt = base64_encode(serialize($array));
		$url = 'https://statcms.yamoney.ru/v2/';
		$curlOpt = array(
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_POST => true,
		);

		$curlOpt[CURLOPT_HTTPHEADER] = array('Content-Type: application/x-www-form-urlencoded');
		$curlOpt[CURLOPT_POSTFIELDS] = http_build_query(array('data' => $array_crypt, 'lbl'=> 0));

		$curl = curl_init($url);
		curl_setopt_array($curl, $curlOpt);
		$rbody = curl_exec($curl);
		$errno = curl_errno($curl);
		$error = curl_error($curl);
		$rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		$json=json_decode($rbody);
		if ($rcode==200 && isset($json->new_version)){
			return $json->new_version;
		}else{
			return false;
		}
	}
}
}

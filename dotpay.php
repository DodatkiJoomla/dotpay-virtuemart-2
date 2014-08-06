<?php
/**
 *  @copyright Copyright (c) 2014 DodatkiJoomla.pl
 *  @license GNU/GPL v2
 */
if (!defined('_VALID_MOS') && !defined('_JEXEC')) die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


// jeżeli klasa vmPSPlugin nie istnieje, dołącz
if (!class_exists('vmPSPlugin'))
{
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
	
class plgVmPaymentDotpay extends vmPSPlugin
{

    public static $_this = false;

	// konstruktor
    function __construct(& $subject, $config) 
	{
		// konstruktor kl. nadrzędnej
		parent::__construct($subject, $config);
		
		$this->_loggable = true;
		
	
		// to poniżej apisuje wartości z xml'a do kol. payment_params tabeli #__virtuemart_paymentmethods	
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
			'dotpay_id' => array('', 'string'),
			'dotpay_pin' => array('', 'string'),
			'dotpay_waluty' => array('', 'string'),
			'dotpay_lang' => array('', 'string'),
            'dotpay_przelewyonline'  => array(1, 'int'),
            'dotpay_urlc' => array('', 'string'),
            'dotpay_guzik_text' => array('', 'string'),

            'status_pending' => array('', 'string'),
            'status_success' => array('', 'string'),
            'status_canceled' => array('', 'string'),

            'cost_per_transaction' => array(0, 'double'),
            'cost_percent_total' => array(0, 'double'),
            'tax_id' => array(0, 'int'),
            'autoredirect' => array(1, 'int'),
            'powiadomienia' => array(1, 'int'),
			'payment_logos' => array('', 'string'),
			'payment_image' => array('', 'string'),
			'checkout_text' => array('', 'string')
	    );
				
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		
	}
	
	function getTableSQLFields() 
	{
		$SQLfields = array(
			'id' => ' int(11) UNSIGNED NOT NULL AUTO_INCREMENT ',
			'virtuemart_order_id' => ' int(11) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'tax_id' => 'int(11) DEFAULT NULL',
			'dotpay_control' => 'varchar(32) ',
            'kwota_zamowienia' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'waluta_zamowienia' => 'varchar(32) ',
            'kwota_platnosci' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'waluta_platnosci' => 'varchar(32) '
			
		);
		return $SQLfields;
    }
	
	// potwierdzenie zamówienia funkcja 
	
	function plgVmPotwierdzenieDotpay($cart, $order, $auto_redirect = false, $form_method = "GET")
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Inna metoda została wybrana, nie rób nic.
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		if (!class_exists('VirtueMartModelOrders'))
		{
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		
		// konwersja z waluty zamówienia, do waluty płatności
		$this->getPaymentCurrency($method);
        $kwota_zamowienia = $order['details']['BT']->order_total;
        // pobranie 3 znakowego kodu waluty
        $q = 'SELECT currency_code_3 FROM #__virtuemart_currencies WHERE virtuemart_currency_id="' .$method->payment_currency. '" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $waluta_zamowienia = $db->loadResult();
		//$waluta_zamowienia = $method->payment_currency;  tutaj wywala z id z bazy
        $CurrencyObj = CurrencyDisplay::getInstance($method->payment_currency);

        if((is_array($method->dotpay_waluty) && count($method->dotpay_waluty)>0) )
        {
            if(in_array($waluta_zamowienia, $method->dotpay_waluty))
            {
                 $kwota_platnosci = $kwota_zamowienia;
                 $waluta_platnosci =  $waluta_zamowienia;
            }
            else
            {
                $q = 'SELECT virtuemart_currency_id FROM #__virtuemart_currencies WHERE currency_code_3="' .$method->dotpay_waluty[0]. '" ';
                $db = &JFactory::getDBO();
                $db->setQuery($q);
                $currency_id = $db->loadResult();
                $kwota_platnosci = number_format($CurrencyObj->convertCurrencyTo($currency_id, $order['details']['BT']->order_total, false),2,".","");
                $waluta_platnosci = $method->dotpay_waluty[0];
            }
        }
        else if(is_string($method->dotpay_waluty) && !empty($method->dotpay_waluty))
        {
            // nie jest tabilą, ale jest stringiem - parametry w J1.5 i brak 'multiple'
            if($waluta_zamowienia==$method->dotpay_waluty)
            {
                $kwota_platnosci = $kwota_zamowienia;
                $waluta_platnosci =  $waluta_zamowienia;
            }
            else
            {
                $q = 'SELECT virtuemart_currency_id FROM #__virtuemart_currencies WHERE currency_code_3="' .$method->dotpay_waluty. '" ';
                $db = &JFactory::getDBO();
                $db->setQuery($q);
                $currency_id = $db->loadResult();
                $kwota_platnosci = number_format($CurrencyObj->convertCurrencyTo($currency_id, $order['details']['BT']->order_total, false),2,".","");
                $waluta_platnosci = $method->dotpay_waluty[0];
            }
        }
        else
        {
            $kwota_platnosci = number_format($CurrencyObj->convertCurrencyTo(114, $order['details']['BT']->order_total, false),2,".",""); // konwertuj do PLN, 114 - id złotówki
            $waluta_platnosci = "PLN";
        }


		// zmienne
        $zamowienie = $order['details']['BT'];
		$session_id = md5($zamowienie->order_number.'|'.time());
        $q = 'SELECT country_3_code FROM #__virtuemart_countries WHERE virtuemart_country_id='.$zamowienie->virtuemart_country_id.' ';        // kraj
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $country = $db->loadResult();
        $url = JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$order['details']['BT']->virtuemart_paymentmethod_id;
		
				
		$this->_virtuemart_paymentmethod_id = $zamowienie->virtuemart_paymentmethod_id;
		$dbWartosci['order_number'] = $zamowienie->order_number;
		$dbWartosci['payment_name'] = $this->renderPluginName($method, $order);
		$dbWartosci['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbWartosci['tax_id'] = $method->tax_id;
		
		// wartości Dotpay
		$dbWartosci['dotpay_control'] = $session_id;
        $dbWartosci['kwota_zamowienia'] = $kwota_zamowienia ;
        $dbWartosci['waluta_zamowienia'] = $waluta_zamowienia;
        $dbWartosci['kwota_platnosci'] = $kwota_platnosci;
        $dbWartosci['waluta_platnosci'] = $waluta_platnosci;

		// zapisz do bazy
		$this->storePSPluginInternalData($dbWartosci);                    		
		
		// zawartośc HTML na podstronie potwierdzenia zamówienia //Numer zamówienia: '.$order['details']['BT']->order_number.'
		$html = '
		<div style="text-align: center; width: 100%; ">
		<form action="https://ssl.dotpay.pl/" method="'.$form_method.'" class="form" name="platnosc_dotpay" id="platnosc_dotpay">
			<input type="hidden" name="id" value="'.$method->dotpay_id.'" />
			<input type="hidden" name="kwota" value="'.$kwota_platnosci.'" />
			<input type="hidden" name="waluta" value="'.$waluta_platnosci.'" />
			<input type="hidden" name="control" value="'.$session_id.'" />
			<input type="hidden" name="opis" value="Zamówienie nr '.$order['details']['BT']->order_number.'" />
			<input type="hidden" name="lang" value="'.$method->dotpay_lang.'" />
            <input type="hidden" name="przelewyonline" value="'.$method->dotpay_przelewyonline.'" />
            <input type="hidden" name="typ" value="0" />
            <input type="hidden" name="txtguzik" value="'.$method->dotpay_guzik_text.'" />
            <input type="hidden" name="url" value="'.$url.'" />
            <input type="hidden" name="urlc" value="'.$method->dotpay_urlc.'" />
            <input type="hidden" name="firstname" value="'.$zamowienie->first_name.'" />
            <input type="hidden" name="lastname" value="'.$zamowienie->last_name.'" />
            <input type="hidden" name="email" value="'.$zamowienie->email.'" />
            <input type="hidden" name="city" value="'.$zamowienie->city.'" />
            <input type="hidden" name="postcode" value="'.$zamowienie->zip.'" />
            <input type="hidden" name="phone" value="'.$zamowienie->phone_1.'" />
            <input type="hidden" name="country" value="'.$country.'" />
			';
			

		if(file_exists(JPATH_BASE.DS.'images'.DS.'stories'.DS.'virtuemart'.DS.'payment'.DS.$method->payment_image))
		{
			$pic = getimagesize(JPATH_BASE.DS.'images/stories/virtuemart/payment/'.$method->payment_image);
			$html .= '		  
		  <input name="submit_send" value="" type="submit" style="border: 0; background: url(\''.JURI::root().'images/stories/virtuemart/payment/'.$method->payment_image.'\'); width: '.$pic[0].'px; height: '.$pic[1].'px; cursor: pointer;" /> ';
		}
		else
		{
			$html .= '<input name="submit_send" value="Zapłać z Dotpay" type="submit"  style="width: 110px; height: 45px;" /> ';
		}
		
		$html .= '	</form>
		<p style="text-align: center; width: 100%; ">'.$method->checkout_text.'</p>
		</div>
		';
		
		// automatyczne przerzucenie do płatności
		if($method->autoredirect && $auto_redirect)
		{
			$html .= '
			<script type="text/javascript">
				window.addEvent("load", function() { 
					document.getElementById("platnosc_dotpay").submit();
					});
			</script>';
		}

		return $html;
	}
	
	function plgVmConfirmedOrder($cart, $order)
	{
		// jeżeli nie zwraca $html - wyrzuc false
		if (!($html = $this->plgVmPotwierdzenieDotpay($cart, $order, true, "POST"))) {
			return false; 
		}
		
		// nazwa płatnosci - zmiana dla Joomla 2.5 !!!
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) 
		{
			return null;
		}
		$nazwa_platnosci = $this->renderPluginName($method);
		
		// tutaj w vm 2.0.2 trzeba dodać status na końcu, zeby się nie wywalało
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $nazwa_platnosci, $method->status_pending);
	}
	
	// zdarzenie po otrzymaniu poprawnego lub błędnego url'a z systemu payu
	function plgVmOnPaymentResponseReceived(&$html) 
	{
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; 
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}


        // info po powrocie
        if(isset($_POST['status']) && $_POST['status']=="OK" && !isset($_POST['md5']))
        {
            // pozytywna
            JFactory::getApplication()->enqueueMessage( 'Dziękujemy za dokonanie transakcji za pośrednictwem Dotpay.' );
            return true;
        }
        elseif(isset($_POST['status']) && $_POST['status']=="FAIL" && !isset($_POST['md5']))
        {
            // negatywna
            JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> wystąpił błąd na podstronie płatności Dotpay.');
            return true;
        }

        // potwierdzenie (callback z Dotpay)
		if(isset($_POST['md5']) &&  $_SERVER['REMOTE_ADDR']=="195.150.9.37")
		{
			// dane z requesta Dotpay
            $payment_data = $_POST;


            // dane z bazy
            $db = &JFactory::getDBO();
            $q = 'SELECT dotpay.*, ord.order_status, usr.email  FROM '.$this->_tablename.' as dotpay JOIN `#__virtuemart_orders` as ord using(virtuemart_order_id) JOIN #__virtuemart_order_userinfos  as usr using(virtuemart_order_id)  WHERE dotpay.dotpay_control="' .$payment_data['control']. '" ';

            $db->setQuery($q);
            $payment_db = $db->loadObject();

            if(!empty($payment_db))
            {
                $md5_request = $payment_data['md5'];
                $md5_vm["PIN"] = $method->dotpay_pin;
                $md5_vm["id"] = $method->dotpay_id;
                $md5_vm["control"] = $payment_db->dotpay_control;
                $md5_vm["t_id"] = $payment_data['t_id'];
                $md5_vm["amount"] = $payment_data['amount'];
                $md5_vm["email"] = $payment_data['email'];
                $md5_vm["service"] = "";
                $md5_vm["code"] = "";
                $md5_vm["username"] = "";
                $md5_vm["password"] = "";
                $md5_vm["t_status"] = $payment_data['t_status'];
                $md5_db = md5(implode(":",$md5_vm));



                if($md5_request==$md5_db)
                {

                    echo "OK\r\n";
                    switch($payment_data['t_status'])
                    {
                        case 1:
                            // nowa
                            // null
                            break;
                        case 2:
                            // wykonana
                            // status_success
                            if($payment_db->order_status!="C" && $payment_db->order_status!='X')
                            {
                                $virtuemart_order_id = $payment_db->virtuemart_order_id;
                                $message = 'Płatność została potwierdzona.';
                                if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_success, $message, $method->powiadomienia))==false)
                                {
                                    //$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_success);
                                }
                                else
                                {
                                    //$this->logInfo('plgVmOnPaymentResponseReceived Potwierdzono zmianę statusu zamówienia na '.$method->status_success);
                                }
                            }
                            break;
                        case 3:
                            // odmowana
                            // null
                            break;
                        case 4:
                            // anulowana / zwrot
                            // status_canceled
                            if($payment_db->order_status!="C" && $payment_db->order_status!='X')
                            {
                                $virtuemart_order_id = $payment_db->virtuemart_order_id;
                                $message = 'Płatność została anulowana.';

                                if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_canceled, $message, $method->powiadomienia))==false)
                                {
                                    //$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_canceled);
                                }
                                else
                                {
                                    //$this->logInfo('plgVmOnPaymentResponseReceived Potwierdzono zmianę statusu zamówienia na '.$method->status_canceled);
                                }
                            }
                            break;
                        case 5:
                            // reklamacja
                            // null
                            break;

                    }

                    exit();
                }
                else
                {
                    $this->logInfo('plgVmOnPaymentResponseReceived Sumy kontrolne nie są identyczne.');
                    exit("FAIL");
                }


            }
            else
            {
                $this->logInfo('plgVmOnPaymentResponseReceived Pusty rekord pobierania informacji nt. zamówienia z bazy danych.');
                exit("FAIL");
            }

		}

		
	}


	
	// wyświetl dane płatności dla zamówienia (backend)
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) 
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null; // Another method was selected, do nothing
		}

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', number_format($paymentTable->kwota_platnosci,2,".","").' '.$paymentTable->waluta_platnosci);
		$html .= '</table>' . "\n";
		return $html;
    }
	
	
	// moja funkcja nowego statusu
	function nowyStatus($virtuemart_order_id, $nowy_status, $notatka = "",  $wyslij_powiadomienie=1)
	{
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			
			// załadowanie języka dla templatey zmiany statusu zam. z admina!
			$lang = &JFactory::getLanguage();		
			$lang->load('com_virtuemart',JPATH_ADMINISTRATOR);
			
			$modelOrder = VmModel::getModel('orders');
			$zamowienie = $modelOrder->getOrder($virtuemart_order_id);
			if(empty($zamowienie))
			{
				return false;
			}
			
			$order['order_status'] = $nowy_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['customer_notified'] = $wyslij_powiadomienie;
			$order['comments'] = $notatka;
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

			// last modify + lock płatności w BD
			
			$db = &JFactory::getDBO();
			// sql'e zależne od nowego statusu
			
			if($nowy_status=="C" || $nowy_status=="X")
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW(), locked_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';		
			}
			else
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';
			}

			$db->setQuery($q);
			$wynik = $db->query($q);
			
			if(empty($wynik))
			{
				return false;
			}

			$message = 'Status zamówienia zmienił się.';


			
			return $message;
	}
	
	
	// sprawdź czy płatność spełnia wymagania
	protected function checkConditions($cart, $method, $cart_prices) 
	{
		return true;
	}
	
	
	/*
	*
	*	RESZTA METOD
	*
	*/
	
	
	protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Dotpay Table');
    }
	
	// utwórz opcjonalnie tabelę płatności, zapisz dane z xml'a itp.
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) 
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
	
	// zdarzenie po wyborze płatności (front)
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) 
	{
		return $this->OnSelectCheck($cart);
    }
		
	// zdarzenie wywoływane podczas listowania płatności
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) 
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) 
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		 $this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }
	
	// sprawdza ile pluginów płatności jest dostepnych, jeśli tylko jeden, użytkownik nie ma wyboru
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) 
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
	
	// zdarzenie wywoływane podczas przeglądania szczegółów zamówienia (front)
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) 
	{	
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
	
	 // funkcja wywołująca stricte zawartość komórki payment w szczegółach zamówienia (front - konto usera)
	 function onShowOrderFE($virtuemart_order_id, $virtuemart_method_id, &$method_info)
	 {
	 	if (!($this->selectedThisByMethodId($virtuemart_method_id))) {
			return null;
		}
		
		// ograniczenie generowania się dodatkowego fomrularza, jeśli klient nie opłacił jeszcze zamówienia, tylko do szczegółów produktu
		// dodatkowo w zależności od serwera, tworzenie faktury w PDF głupieje czasami przy obrazkach dla płatności 
		if(isset($_REQUEST['view']) && $_REQUEST['view']=='orders' && isset($_REQUEST['layout']) && $_REQUEST['layout']=='details')
		{	 
			// wywołaj cały formularz
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			if (!class_exists('VirtueMartCart'))
			{
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
			}	
			if (!class_exists('CurrencyDisplay'))
			{
				require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
			}
			$modelOrder = new VirtueMartModelOrders();
			$cart = VirtueMartCart::getCart();
			$order = $modelOrder->getOrder($virtuemart_order_id);

			
			if (!($html = $this->plgVmPotwierdzenieDotpay($cart, $order, false ,"POST")) || $order['details']['BT']->order_status=='C' || $order['details']['BT']->order_status=='U' ) 
			{			
				$method_info = $this->getOrderMethodNamebyOrderId($virtuemart_order_id);
			}
			else
			{
				$method_info = $html;
			}
		}
		else
		{
			$method_info = 'Dotpay';
		}
	 }
	 
	// pobranie nazwy płatności z bazy 
	function getOrderMethodNamebyOrderId ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id.  ' ORDER BY id DESC LIMIT 1 ';
		$db->setQuery ($q);
		if (!($pluginInfo = $db->loadObject ())) {
			vmWarn ('Attention, ' . $this->_tablename . ' has not any entry for the order ' . $db->getErrorMsg ());
			return NULL;
		}
		$idName = $this->_psType . '_name';

		return $pluginInfo->$idName;
	}
	
	 /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */

	// wymagane aby zapis XML'a do BD działał
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	
		// nadpisujemy parametr , aby edycja nic mu nie robiła!
		$virtuemart_paymentmethod_id = $_GET['cid'][0];
		$urlc = 'dotpay_urlc="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'"|';
        $data->payment_params .= $urlc;
		
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }
}
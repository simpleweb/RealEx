<?php
class Realex_Eft extends Realex_Base
{

    private $_realexEndpoint = 'https://epage.payandshop.com/epage-remote-plugins.cgi';
    private $_httpClient = null;

    function Realex_Eft()
    {

        $this->_httpClient = new Zend_Http_Client($this->_realexEndpoint);
        $this->_httpClient->setConfig(array('timeout' => 10, 'maxredirects' => 0));
        $this->_httpClient->setHeaders('Accept', 'application/xml');
        $this->_httpClient->setHeaders('Content-Type', 'application/xml');

    }

    function NewPayer(Realex_Payer $payer)
    {

        return $this->doRequest($payer->GetXml());

    }

    function UpdatePayer(Realex_Payer $payer)
    {

        return $this->doRequest($payer->GetXml(true));

    }

    function NewCard(Realex_Card $card)
    {

        //Don't audit this because of the credit card details.
        return $this->doRequest($card->GetNewCardXml(), false);

    }

    function RaisePayment(Realex_Payment $payment)
    {

        return $this->doRequest($payment->GetXml(), true, $payment->orderID);

    }

    private function doRequest($xmlString, $audit = true, $orderID = 0)
    {

        $this->_httpClient->setRawData($xmlString, 'text/xml');
        $response = $this->_httpClient->request('POST');

        if ($response->getStatus() == 200) {

            $response = $response->getBody();

            $reResponse = new Realex_Response($response);

            return $reResponse;

        } else {
            $this->SendDebugEmail('Error communicating with RealEx', $response);
            return false;
        }

    }

}

/**
 * Loads response back from Realex
 * @author tom
 *
 */
class Realex_Response
{

    public $result = -1;
    public $message;

    function Realex_Response($xmlString)
    {

        try {
            $xml = simplexml_load_string($xmlString);

            $this->result = (int) $xml->result;
            $this->message = $xml->message;
        } catch(Exception $e) {
            $this->result = 999;
            $this->message = $e->getMessage();
        }
    }

}

class Realex_Payer extends Realex_Base
{

    public $ref;

    public $title;
    public $forename;
    public $surname;
    public $company;
    public $address1;
    public $address2;
    public $address3;
    public $town;
    public $region;
    public $country;
    public $countryCode;
    public $postCode;
    public $phoneNumber;
    public $email;

    function Realex_Payer($id = null, $invoiceContact = null)
    {

        //Interporability between invoice contact and realex payer.
        //Very similar! Allowers creating realex payer from invoice contact.
        if ($id && $invoiceContact) {

            $this->ref = $id;
            $this->forename = $invoiceContact->forename;
            $this->surname = $invoiceContact->surname;
            $this->company = $invoiceContact->company;
            $this->address1 = $invoiceContact->addressLine1;
            $this->address2 = $invoiceContact->addressLine3;
            $this->address3 = $invoiceContact->addressLine3;
            $this->town = $invoiceContact->town;
            $this->region = $invoiceContact->county;
            $this->country = $invoiceContact->country;
            $this->countryCode = $invoiceContact->countryCode;
            $this->postCode = $invoiceContact->postCode;
            $this->phoneNumber = $invoiceContact->tel;
            $this->email = $invoiceContact->email;
        }
    }

/**
     * Returns contact as XML in the correct format.
     * @return unknown_type
     */
    public function GetXml($existing = false)
    {

        if (!$this->ref) {
            throw new Exception('Payer ref required.');
        }

        if (!$this->forename) {
            throw new Exception('Payer forename required.');
        }

        if (!$this->surname) {
            throw new Exception('Payer surname required.');
        }

        $timestamp = $this->GetTimestamp();
        $merchantid = $this->GetMerchantID();
        $orderid = $this->GetUniqueOrderRef();

        $amount = '';
        $currency = '';

        $xml = new SimpleXMLElement('<request></request>');

        if ($existing) {
            $xml->addAttribute('type', 'payer-edit');
        } else {
            $xml->addAttribute('type', 'payer-new');
        }

        $xml->addAttribute('timestamp', $timestamp);


        $xml->addChild('merchantid', $merchantid);
        $xml->addChild('orderid', $orderid);

        $payer = $xml->addChild('payer');
        $payer->addAttribute('type', 'Business');
        $payer->addAttribute('ref', $this->ref);
        $payer->addChild('title', $this->title);
        $payer->addChild('firstname', $this->forename);
        $payer->addChild('surname', $this->surname);
        $payer->addChild('company', $this->company);

        //Address
        $address = $payer->addChild('address');
        $address->addChild('line1', $this->address1);
        $address->addChild('line2', $this->address2);
        $address->addChild('line3', $this->address3);
        $address->addChild('city', $this->town);
        $address->addChild('county', $this->region);
        $address->addChild('postcode', $this->postCode);
        $country = $address->addChild('country', $this->country);
        $country->addAttribute('code', $this->countryCode);

        //Phone numbers
        $phonenumbers = $payer->addChild('phonenumbers');
        $phonenumbers->addChild('work', $this->phoneNumber);

        $payer->addChild('email', $this->email);

        //timestamp.merchant_id.order_id.amount.currency.payerref
        $sigData = "$timestamp.$merchantid.$orderid.$amount.$currency.{$this->ref}";

        $xml->addChild('sha1hash', $this->GetSigHash($sigData));

        return $xml->asXML();
    }

}

class Realex_Card extends Realex_Base
{

    public $ref;
    public $number;
    public $expiry;
    public $holder;
    public $type;
    public $issueNo;

    public $payer;

    /**
     * Constructor
     * @param Realex_Payer $payer
     * @return unknown_type
     */
    function Realex_Card(Realex_Payer $payer)
    {

        if (!$payer) {
            throw new Exception('Realex_Card requires valid Realex_Payer');
        }

        $this->payer = $payer;

    }

    /**
     * XML for adding a new card.
     * @return unknown_type
     */
    public function GetNewCardXml()
    {

        if (!$this->ref) {
            throw new Exception('Card ref required');
        }

        if (!$this->payer || !$this->payer->ref) {
            throw new Exception('Payer ref required');
        }

        if (!$this->number) {
            throw new Exception('Card number required');
        }

        if (!$this->expiry) {
            throw new Exception('Card number required');
        }

        if (!$this->holder) {
            throw new Exception('Card holder required');
        }

        if (!$this->type) {
            throw new Exception('Card type required');
        }

        $timestamp = $this->GetTimestamp();
        $merchantid = $this->GetMerchantID();
        $orderid = $this->GetUniqueOrderRef();
        $amount = '';
        $currency = '';

        $xml = new SimpleXMLElement('<request></request>');

        $xml->addAttribute('type', 'card-new');
        $xml->addAttribute('timestamp', $timestamp);

        $xml->addChild('merchantid', $merchantid);
        $xml->addChild('orderid', $orderid);

        $card = $xml->addChild('card');
        $card->addChild('ref', $this->ref);
        $card->addChild('payerref', $this->payer->ref);
        $card->addChild('number', $this->number);
        $card->addChild('expdate', $this->expiry);
        $card->addChild('chname', $this->holder);
        $card->addChild('type', $this->type);
        $card->addChild('issueno', $this->issueNo);

        //timestamp.merchant_id.order_id.amount.currency.cardpayerref.cardname.cardnumber
        $sigData
            = "$timestamp.$merchantid.$orderid.$amount.$currency.{$this->payer->ref}.{$this->holder}.{$this->number}";

        $xml->addChild('sha1hash', $this->GetSigHash($sigData));

        return $xml->asXML();

    }

    /**
     * XML for adding a new card.
     * @return unknown_type
     */
    public function GetUpdateExpiryXml()
    {

        $timestamp = $this->GetTimestamp();
        $merchantid = $this->GetMerchantID();
        $orderid = microtime();
        $amount = '';
        $currency = '';

        $xml = new SimpleXMLElement('<request></request>');

        $xml->addAttribute('type', 'eft-update-expiry-date');
        $xml->addAttribute('timestamp', $timestamp);

        $xml->addChild('merchantid', $merchantid);

        $card = $xml->addChild('card');
        $card->addChild('ref', $this->ref);
        $card->addChild('payerref', $this->payer->ref);
        $card->addChild('expdate', $this->cardExpiry);

        //Timestamp.merchantID.payerref.ref.expirydate
        $sigData = "$timestamp.$merchantid.{$this->Payer->Ref}.{$this->Ref}.{$this->CardExpiry}";

        $xml->addChild('sha1hash', $this->GetSigHash($sigData));

        return $xml->asXML();

    }

}

class Realex_Payment extends Realex_Base
{

    public $orderID;
    public $amount;
    public $currency = 'GBP';
    public $cvn;
    public $autoSettle = "1";

    public $payer;
    public $card;

    /**
     * Constructor
     * @param Realex_Payer $payer
     * @return unknown_type
     */
    function Realex_Payment(Realex_Payer $payer, Realex_Card $card)
    {

        if (!$payer) {
            throw new Exception('Realex_Card requires valid Realex_Payer');
        }

        $this->payer = $payer;

        if (!$card) {
            throw new Exception('Realex_Card requires valid Realex_Payer');
        }

        $this->card = $card;

    }

    public function GetXml()
    {

        if (!$this->orderID) {
            throw new Exception('OrderID required');
        }

        if (!$this->amount) {
            throw new Exception('Amount required.');
        }

        if (!$this->card || !$this->card->ref) {
            throw new Exception('Card/ref required.');
        }

        if (!$this->payer || !$this->payer->ref) {
            throw new Exception('Payer/ref required.');
        }

        //Not actually required by realex but we are forcing it.
        if (!$this->cvn) {
            throw new Exception('Cvn required.');
        }

        $timestamp = $this->GetTimestamp();
        $merchantid = $this->GetMerchantID();
        $orderid = $this->orderID;
        $account = $this->GetAccount();

        $xml = new SimpleXMLElement('<request></request>');

        $xml->addAttribute('type', 'receipt-in');
        $xml->addAttribute('timestamp', $timestamp);

        $xml->addChild('merchantid', $merchantid);
        $xml->addChild('account', $account);
        $xml->addChild('orderid', $orderid);

        $amount = $xml->addChild('amount', $this->amount);
        $amount->addAttribute('currency', $this->currency);

        $paymentdata = $xml->addChild('paymentdata');
        $cvn = $paymentdata->addChild('cvn');
        $cvn->addChild('number', $this->cvn);

        $autosettle = $xml->addChild('autosettle');
        $autosettle->addAttribute('flag', $this->autoSettle);

        $xml->addChild('payerref', $this->payer->ref);
        $xml->addChild('paymentmethod', $this->card->ref);

        //timestamp.merchant_id.order_id.amount.currency.payerref
        $sigData = "$timestamp.$merchantid.$orderid.{$this->Amount}.{$this->Currency}.{$this->Payer->Ref}";

        $xml->addChild('sha1hash', $this->GetSigHash($sigData));

        $xml->addChild('sha1hash', $this->GetSigHash($sigData));

        return $xml->asXML();

    }

}

class Realex_Base
{

    //Add payer
    //timestamp.merchant_id.order_id.amount.currency.payerref

    //Edit payer
    //timestamp.merchant_id.order_id.amount.currency.payerref

    //Add card
    //timestamp.merchant_id.order_id.amount.currency.cardpayerref.cardname.cardnumber

    //Update card expiry
    //Timestamp.merchantID.payerref.ref.expirydate

    //Raise payment
    //timestamp.merchant_id.order_id.amount.currency.payerref

    /**
     * REturns timestamp in the format realex expects.
     * @return unknown_type
     */
    protected function GetTimestamp()
    {
        return date('YmdHis', time());
    }

    /**
     * Could do with extending this later so that the shared key is coming out of the config file.
     * @return unknown_type
     */
    protected function GetSharedKey()
    {

        $this->config = Zend_Registry::get('config');

        return $this->config->realex->sharedsecret;
    }

    /**
     * Could do with extending this later so that the shared key is coming out of the config file.
     * @return unknown_type
     */
    protected function GetMerchantID()
    {

        $this->config = Zend_Registry::get('config');

        return $this->config->realex->merchantid;
    }
    /**
     * Could do with extending this later so that the shared key is coming out of the config file.
     * @return unknown_type
     */
    protected function GetAccount()
    {

        $this->config = Zend_Registry::get('config');

        //Live
        return $this->config->realex->account;
    }

    protected function GetUniqueOrderRef()
    {
        return str_ireplace('.', '', microtime(true));
    }

    /**
     * Generates secure hash signatures for API requests.
     * @param $data
     * @param $method
     * @return unknown_type
     */
    protected function GetSigHash($data, $method='sha1')
    {

        $sharedKey = $this->GetSharedKey();

        if ($method=='sha1') {

            $sha1hash = sha1($data);
            $tmp = "$sha1hash.$sharedKey";
            return sha1($tmp);

        } else { //MD5

            $md5hash = md5($data);
            $tmp = "$md5hash.$sharedKey";
            return md5($tmp);
        }

    }

}
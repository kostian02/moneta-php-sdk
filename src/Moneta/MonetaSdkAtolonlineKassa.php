<?php

namespace Moneta;

use Moneta;

class MonetaSdkAtolonlineKassa implements MonetaSdkKassa
{
    public $kassaApiUrl;

    public $kassaApiVersion;

    public $associatedLogin;

    public $associatedPassword;

    public $groupCode;

    public $kassaInn;

    public $kassaAddress;

    public $kassaStorageSettings;

    public function __construct($storageSettings)
    {
        $this->kassaStorageSettings = $storageSettings;
        $this->kassaApiUrl = $this->kassaStorageSettings['monetasdk_kassa_atol_api_url'];
        $this->kassaApiVersion = $this->kassaStorageSettings['monetasdk_kassa_atol_api_version'];
        $this->associatedLogin = $this->kassaStorageSettings['monetasdk_kassa_atol_login'];
        $this->associatedPassword = $this->kassaStorageSettings['monetasdk_kassa_atol_password'];
        $this->groupCode = $this->kassaStorageSettings['monetasdk_kassa_atol_group_code'];
        $this->kassaInn = $this->kassaStorageSettings['monetasdk_kassa_inn'];
        $this->kassaAddress = $this->kassaStorageSettings['monetasdk_kassa_address'];

    }

    public function __destruct()
    {

    }

    public function authoriseKassa()
    {
        $data = array("login" => $this->associatedLogin, "pass" => $this->associatedPassword);
        $url = $this->kassaApiUrl . "/" . $this->kassaApiVersion . "/";
        $method = "getToken";
        $result = $this->sendHttpRequest($url, $method, $data);
        $result = @json_decode($result, true);
        return (isset($result['token'])) ? $result['token'] : false;
    }

    public function checkKassaStatus()
    {

    }

    public function sendDocument($document)
    {
        $tokenid = $this->authoriseKassa();
        if (!$tokenid) {
            return false;
        }

        $url = $this->kassaApiUrl . "/" . $this->kassaApiVersion . "/";
        $method = $this->groupCode . "/sell";

        // данные чека
        $document = @json_decode($document, true);

        $d = new \DateTime($document['checkoutDateTime']);
        $data = array('timestamp' => $d->format('d.m.Y H:i:s'), 'external_id' => 'atol-' . $document['docNum']);
        $data['receipt']['attributes']['email'] = $document['email'];
        $data['receipt']['attributes']['phone'] = '';

        $items = array();
        $inventPositions = $document['inventPositions'];
        if (is_array($inventPositions) && count($inventPositions)) {
            foreach ($inventPositions AS $position) {
                $tax = MonetaSdkKassa::ATOL_NONE;
                switch ($position['vatTag']) {
                    case MonetaSdkKassa::VAT0:
                        $tax = MonetaSdkKassa::ATOL_VAT0;
                        break;
                    case MonetaSdkKassa::VAT18:
                        $tax = MonetaSdkKassa::ATOL_VAT18;
                        break;
                    case MonetaSdkKassa::VATWR10:
                        $tax = MonetaSdkKassa::ATOL_VAT110;
                        break;
                    case MonetaSdkKassa::VATWR18:
                        $tax = MonetaSdkKassa::ATOL_VAT118;
                        break;
                }

                $items[] = array(
                    'price' => floatval($position['price']), 'name' => $position['name'], 'quantity' => intval($position['quantity']),
                    'sum' => floatval($position['price'] * $position['quantity']), 'tax' => $tax
                );
            }
        }

        $data['receipt']['items'] = $items;

        $totalAmount = 0;
        $payments = array();
        if (is_array($document['moneyPositions']) && count($document['moneyPositions'])) {
            foreach ($document['moneyPositions'] AS $moneyPosition) {
                $payments[] = array('type' => 1, 'sum' => floatval($moneyPosition['sum']));
                $totalAmount = $totalAmount + $moneyPosition['sum'];
            }
        }

        $data['receipt']['payments'] = $payments;
        $data['receipt']['total'] = $totalAmount;

        $data['service']['inn'] = $this->kassaInn;

        if (isset($document['responseURL']) && $document['responseURL']) {
            $data['service']['callback_url'] = $document['responseURL'];
        }

        $data['service']['payment_address'] = $this->kassaAddress;

        $respond = $this->sendHttpRequest($url, $method, $data, $tokenid);

        $result = false;
        // пример ответа
        // {"uuid":"ea5991ab-05f3-4c10-980a-3b3f3d58ed13","timestamp":"18.05.2017 16:33:23","status":"wait","error":null}
        if ($respond) {
            $respondArray = @json_decode($respond, true);
            if (is_array($respondArray) && count($respondArray)) {
                foreach ($respondArray AS $respondItemKey => $respondItemValue) {
                    if ($respondItemKey == 'error' && (!$respondItemValue || $respondItemValue == 'null')) {
                        $result = true;
                    }
                }
            }
        }

        return $result;
    }

    public function checkDocumentStatus()
    {

    }

    private function sendHttpRequest($url, $method, $data, $tokenid = null)
    {
        // запрос надо сделать через curl
        $jsonData = json_encode($data);

        if ($this->kassaStorageSettings['monetasdk_debug_mode']) {
            MonetaSdkUtils::addToLog("sendHttpRequest atolonline Request:\n" . $url . $method . "\n" . "jsonData: " . $jsonData . "\n");
        }

        $operationUrl = $url . $method;
        if ($tokenid) {
            $operationUrl .= "?tokenid=" . $tokenid;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $operationUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData))
        );

        $result = curl_exec($ch);

        $res = curl_exec($ch);
        if ($res === false) {
            if ($this->kassaStorageSettings['monetasdk_debug_mode']) {
                MonetaSdkUtils::addToLog("sendHttpRequest atolonline Response error:\n" . var_export(curl_error($ch), true) . "\n");
            }
        }
        else {
            if ($this->kassaStorageSettings['monetasdk_debug_mode']) {
                MonetaSdkUtils::addToLog("sendHttpRequest atolonline Response:\n" . $result . "\n");
            }
        }

        curl_close($ch);
        return $result;

    }


}
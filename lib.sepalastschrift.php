<?php

/*
 * Copyright (c) 2013, Michael Braun <michael-dev@fami-braun.de>.
 * All rights reserved.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301 USA
 *
 * https://github.com/michael-dev/php-sepa-lastschrift
 */
global $sepaLastschriftXMLVersion; // 008.002.02
global $sepaLastschriftXSD;

// ../media/

/**
 * Diese Klasse implementiert pain.008.003.02
 * für SEPA Lastschriften
 * Payment-Id := <Message-Id>-<Type>
 */
class SEPALastschrift
{
    /**
     * Y-m-d\TH:i:s\Z
     * @var DateTime
     */
    protected $date;

    /**
     * ([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35}
     * @string
     */
    protected $msgid;

    /**
     * 
     * @var array
     */
    protected $txs = [];

    protected $initator = "missing";
    
    /**
     * @var array $type => cents 
     */
    protected $sum = [];

    protected $creditorName = "missing";

    protected $creditorIBAN = "missing";

    protected $creditorBIC = "missing";

    /**
     * Gläubiger-ID
     * @var string
     */
    protected $creditorID = "missing";

    /**
     * 
     * @var string EUR 
     */
    protected $ccy = "EUR";

    protected $txTypes = [
        "FRST",
        "RCUR",
        "OOFF",
        "FNAL"
    ];

    protected $cdtype = "CORE";

    protected $BtchBookg = NULL;

    protected $errors = [];
    
    /**
     *
     * @param DateTime $date
     * @param string $msgid ein Prefix, welches auch für sie Sammler (PmtInfId) als Prefix verwendet wird. Daher die Länge auf 29 beschränken!
     * @param string $initiator
     * @param string $creditorName
     * @param string $creditorIBAN
     * @param string $creditorBIC
     * @param string $creditorID GläubigerID
     * @param string [$ccy]
     * @param string [$cdtype]
     * @param boolean|NULL [$BtchBookg]
     */
    public function __construct($date, $msgid, $initiator, $creditorName, $creditorIBAN, $creditorBIC, $creditorID, $ccy = "EUR", $cdtype = "CORE" /* B2B or COR1 */, $BtchBookg = NULL /* BatchBookingIndicator */) {
        $this->date = $date;
        $this->msgid = $msgid;
        $this->initiator = $initiator;
        $this->creditorName = $creditorName;
        $this->creditorIBAN = $creditorIBAN;
        $this->creditorBIC = $creditorBIC;
        $this->creditorID = $creditorID;
        $this->ccy = $ccy;
        $this->cdtype = $cdtype;
        $this->BtchBookg = $BtchBookg;
        if (! preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,29}$#", $msgid)) {
            $this->errors[] = "ungültige msgid";
        }
    }

    /**
     * Lastschrift einfügen.
     *
     * @param string $txn_id
     * @param string $iban
     * @param string $bic
     * @param string $name Kontoinhaber
     * @param string $mandate Mandat-ID
     * @param DateTime $mandateSignatureDate Datum der Unterschrift auf dem Mandat
     * @param float $amount maximal 2 Bruchziffern
     * @param string $subject Verwendungszweck
     * @param string $type sepatyp "FRST","RCUR","OOFF","FNAL"
     * @param string [$UltmtDbtr] Name des Schuldners (nur Information; wenn abweichend von Kontoinhaber) (optional)
     * 
     * @return boolean 
     */
    public function addLastschrift($txn_id, $iban, $bic, $name, $mandate, $mandateSignatureDate, $amount, $subject, $type, $UltmtDbtr = NULL)
    {
        $this->errors = [];

        $this->checkTxnId($txn_id);
        $this->checkIBAN($iban);
        $this->checkBIC($bic);
        $this->checkMandate($mandate);
        $this->checkSubject($subject);
        $this->checkName($name);
        $this->checkUltmtDbtr($UltmtDbtr);
       
       
        if ($amount < 0.01 || $amount > 999999999.99) {
            $this->errors[] = "invalid amount $amount";
        }
        $amount = round($amount * 100);
        if (! in_array($type, $this->txTypes)) {
            $this->errors[] = "invalid type $type";
        }

        if(count($this->errors)) {
            static::onError($this->errors);
            return false;
        }

        $tx = Array(
            "id" => $txn_id,
            "IBAN" => $iban,
            "BIC" => $bic,
            "name" => $name,
            "mandate" => $mandate,
            "mandateSignatureDate" => $mandateSignatureDate,
            "amount" => $amount,
            "subject" => $subject
        );
        if ($UltmtDbtr !== NULL) {
            $tx["UltmtDbtr"] = $UltmtDbtr;
        }
        if (! isset($this->txs[$type])) {
            $this->txs[$type] = Array();
        }
        $this->txs[$type][] = $tx;
        if (! isset($this->sum[$type])) {
            $this->sum[$type] = 0;
        }
        $this->sum[$type] += $amount;

        return true;
    }
    
    protected function checkIBAN(&$iban) {
        if(!strlen($iban))
        {
            $this->errors[] = "Keine IBAN";
        }
        $iban = strtoupper($iban);
    }
    
    protected function checkBIC(&$bic) {
        $bic = strtoupper($bic);
    }
    
    protected function checkTxnId(&$txn_id) {
        if (! preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35}$#", $txn_id)) {
            $this->errors[] = "invalid id $txn_id";
        }
    }
    protected function checkMandate(&$mandate) {
        if (! preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|']){1,35}$#", $mandate)) {
            $this->errors[] = "invalid mandate $mandate";
        }
    }
    protected  function checkSubject(&$subject) {
        if (strlen($subject) < 1 or strlen($subject) > 140) {
            $this->errors[] = "invalid subject length";
        }
        // Sparkasse Arnstadt-Ilmenau erlaubt hier nur a-zA-Z0-9 sowie -':?,+()/. und Leerzeichen
        if (! preg_match("#^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,140}$#", $subject)) {
            static::onError(["invalid subject $subject: #^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,140}$#"]);
        }
    }
    protected function checkName(&$name) {
        if (strlen($name) < 1 or strlen($name) > 70) {
            $this->errors[] = "invalid name length";
        }
        // Sparkasse Arnstadt-Ilmenau erlaubt hier nur a-zA-Z0-9 sowie -':?,+()/. und Leerzeichen
        if (! preg_match("#^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,70}$#", $name)) {
            $this->error("invalid name $name: #^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,70}$#");
        }
    }
    protected function checkUltmtDbtr(&$UltmtDbtr) {
        if ($UltmtDbtr !== NULL && (strlen($UltmtDbtr) < 1 or strlen($UltmtDbtr) > 70)) {
            $this->errors[] = "invalid UltmtDbtr length";
        }
    }
    protected static function formatCcy($cents)
    {
        return sprintf("%d.%02d", ($cents / 100), ($cents % 100));
    }

    protected function addGrpHdr($xml)
    {
        $xml->startElement('GrpHdr');
        $xml->writeElement('MsgId', $this->msgid);
        $dt = new DateTime("now", new DateTimeZone('Etc/UTC'));
        $xml->writeElement('CreDtTm', $dt->format('Y-m-d\TH:i:s\Z'));
        $ctn = 0;
        foreach ($this->txs as $v) {
            $ctn += count($v);
        }
        $xml->writeElement('NbOfTxs', $ctn);
        $sum = 0;
        foreach ($this->sum as $v) {
            $sum += $v;
        }
        $xml->writeElement('CtrlSum', $this->formatCcy($sum));
        $xml->startElement('InitgPty');
        $xml->writeElement('Nm', $this->initiator);
        $xml->endElement(); /* InitgPty */
        $xml->endElement(); /* GrpHdr */
    }

    /**
     * $type: FRST; RCUR; OOFF; FNAL
     */
    protected function addPmtInf($xml, $txs, $sum, $type)
    {
        if (! preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,5}$#", $type)) {
            $this->errors[] = "invalid type $type";
        }

        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', $this->msgid . '-' . $type);
        $xml->writeElement('PmtMtd', 'DD');
        if ($this->BtchBookg !== NULL) {
            $xml->writeElement('BtchBookg', $this->BtchBookg ? "true" : "false");
        }
        $xml->writeElement('NbOfTxs', count($txs));
        $xml->writeElement('CtrlSum', $this->formatCcy($sum));
        $xml->startElement('PmtTpInf');
        $xml->startElement('SvcLvl');
        $xml->writeElement('Cd', 'SEPA');
        $xml->endElement(); /* SvcLvl */
        $xml->startElement('LclInstrm');
        $xml->writeElement('Cd', $this->cdtype);
        $xml->endElement(); /* LclInstrm */
        $xml->writeElement('SeqTp', $type);
        $xml->endElement(); /* PmtTpInf */
        $xml->writeElement('ReqdColltnDt', $this->date->format('Y-m-d'));
        $xml->startElement('Cdtr');
        $xml->writeElement('Nm', $this->creditorName);
        $xml->endElement(); /* Cdtr */
        $xml->startElement('CdtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $this->creditorIBAN);
        $xml->endElement(); /* Id */
        $xml->endElement(); /* CdtrAcct */
        $xml->startElement('CdtrAgt');
        $xml->startElement('FinInstnId');
        if ($this->creditorBIC !== NULL) {
            $xml->writeElement('BIC', $this->creditorBIC);
        } // BIC !== NULL
        $xml->endElement(); /* FinInstnId */
        $xml->endElement(); /* CdtrAgt */
        $xml->writeElement('ChrgBr', 'SLEV');
        $xml->startElement('CdtrSchmeId');
        $xml->startElement('Id');
        $xml->startElement('PrvtId');
        $xml->startElement('Othr');
        $xml->writeElement('Id', $this->creditorID);
        $xml->startElement('SchmeNm');
        $xml->writeElement('Prtry', 'SEPA');
        $xml->endElement(); /* SchmeNm */
        $xml->endElement(); /* Othr */
        $xml->endElement(); /* PrvtId */
        $xml->endElement(); /* Id */
        $xml->endElement(); /* CdtrSchmeId */
        foreach ($txs as $tx) {
            $this->addTX($xml, $tx);
        }
        $xml->endElement(); /* PmtInf */
    }

    protected function addTX($xml, $tx)
    {
        $xml->startElement('DrctDbtTxInf');
        $xml->startElement('PmtId');
        $xml->writeElement('EndToEndId', $tx["id"]);
        $xml->endElement(); /* PmtId */
        $xml->startElement('InstdAmt');
        $xml->writeAttribute("Ccy", $this->ccy);
        $xml->text($this->formatCcy($tx["amount"]));
        $xml->endElement(); /* InstdAmt */
        $xml->startElement('DrctDbtTx');
        $xml->startElement('MndtRltdInf');
        $xml->writeElement('MndtId', $tx["mandate"]);
        $xml->writeElement('DtOfSgntr', $tx["mandateSignatureDate"]->format('Y-m-d'));
        /* No Amendment aka Change-Tracing supported! */
        $xml->endElement(); /* MndtRltdInf */
        $xml->endElement(); /* DrctDbtTx */
        $xml->startElement('DbtrAgt');
        $xml->startElement('FinInstnId');
        if ($tx["BIC"] !== NULL) {
            $xml->writeElement('BIC', $tx["BIC"]);
        } // BIC !== null
        $xml->endElement(); /* FinInstnId */
        $xml->endElement(); /* DbtrAgt */
        $xml->startElement('Dbtr');
        $xml->writeElement('Nm', $tx["name"]);
        $xml->endElement(); /* Dbtr */
        $xml->startElement('DbtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $tx["IBAN"]);
        $xml->endElement(); /* Id */
        $xml->endElement(); /* DbtrAcct */
        if (isset($tx["UltmtDbtr"])) {
            $xml->startElement('UltmtDbtr');
            $xml->writeElement('Nm', $tx["UltmtDbtr"]); /* Zahlungspflichtiger, falls vom Kontoinhaber abweichend */
            $xml->endElement(); /* UltmtDbtr */
        }
        $xml->startElement('RmtInf');
        $xml->writeElement('Ustrd', $tx["subject"]);
        $xml->endElement(); /* RmtInf */
        $xml->endElement(); /* DrctDbtTxInf */
    }

    public function asXML()
    {
        global $sepaLastschriftXMLVersion; // 008.002.02
        global $sepaLastschriftXSD; // ../media/
        /**
         * output
         */
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        $painVersion = "008.002.02";
        if (isset($sepaLastschriftXMLVersion)) {
            $painVersion = $sepaLastschriftXMLVersion;
        }
        $painXSDFile = "pain." . $painVersion . ".xsd";

        $xml->startElement('Document');
        $xml->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.' . $painVersion);
        $xml->writeAttributeNS('xsi', 'schemaLocation', 'http://www.w3.org/2001/XMLSchema-instance', 'urn:iso:std:iso:20022:tech:xsd:pain.' . $painVersion . ' ' . $painXSDFile);
        $xml->startElement('CstmrDrctDbtInitn');
        $this->addGrpHdr($xml);
        foreach ($this->txs as $type => $txs) {
            $this->addPmtInf($xml, $txs, $this->sum[$type], $type);
        }
        $xml->endElement(); /* CstmrDrctDbtInitn */
        $xml->endElement(); /* Document */

        $xml->endDocument();
        $xmlString = $xml->outputMemory(TRUE);

        // verify xml
        if (isset($sepaLastschriftXSD)) {
            $xsdFile = $sepaLastschriftXSD . "/" . $painXSDFile;
            if (! is_file($xsdFile)) {
                static::validationError("Die Schema-Datei $xsdFile wurde nicht gefunden.");
                return false;
            } else {
                $tempDom = new DOMDocument();
                $tempDom->loadXML($xmlString);
                if (! $tempDom->schemaValidate($xsdFile)) {
                    $this->validationError("Die erzeugten Daten sind ungültig.");
                    return false;
                }
            }
        }

        return $xmlString;
    }

    protected static function onError($messagesArray) {
        die(print_r($messagesArray,1));
    }
    protected static function validationError($message) {
        //globale Funktion, muss definiert sein
        add_message($message);
    }
}

<?php

class parseXML {

    private $templateIds=array();

    private $relaties;
    private $arrayData;
    private $patientData;
    private $dossierData;
    private $parsedData = array();

    private $bouwsteenCodes = array();

    private $checkDatum;

    public function __construct($checkDatum) {

        $this->templateIds['2.16.840.1.113883.2.4.3.11.60.20.77.10.9411']='medgeg';

		$this->templateIds['2.16.840.1.113883.2.4.3.11.60.20.77.10.9430']='MA';
        $this->templateIds['2.16.840.1.113883.2.4.3.11.60.20.77.10.9429']='MA';


        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9449"]='VV';

        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9416"]='TA';
        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9415"]='TA';

        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9412"]='WDS';

        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9364"]='MVE';

        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9442"]='MGB';
        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9444"]='MGB';

        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9406"]='MTD';
        $this->templateIds["2.16.840.1.113883.2.4.3.11.60.20.77.10.9359"]='???';


        $this->bouwsteenCodes = array(
            '52711000146108'=>'VV',
            '33633005' => 'MA',
            '422037009' => 'TA');

        $this->checkDatum = $checkDatum;

	}



    public function getDataFromArray($inputArray ) {
        // $arrayData = $inputArray;
        // echo '<pre>';
        foreach ($inputArray as $arrayData) {

            $this->getPatient($arrayData);
            $this->getBouwstenen($arrayData);
            // print_r($arrayData);
        }


        //

        $this->setSimpleIds();
        // print_r($this->parsedData);
        // print_r($inputArray);
        return $this->parsedData;

    }

    private function getPatient($arrayData) {
        $patient = $arrayData['recordTarget']['patientRole'];
        $this->patientData['bsn'] = $patient['id']['@attributes']['extension']??'';
        $this->patientData['name'] = $patient['patient']['name']['family']??'';
    }

    private function getBouwstenen($arrayData) {
        //Als er maar 1 bouwsteen in de set zitten, dan heeft het componenent direct een attribute
        // if (empty($arrayData['component']))
        //     return;
        if (isset ($arrayData['component']['@attributes'])) {
            // print_r($arrayData);
            $this->getComponentDetails($arrayData['component']);
        } else {
            //er zitten meerdere components in
            if (isset($arrayData['component'])) {
                $component = $arrayData['component'];
                foreach ($component as $bouwsteenData) {
                    $this->getComponentDetails($bouwsteenData);
                }
            }
        }
        // print_r($this->parsedData);
    }

    private function getComponentDetails($bouwsteenData) {
        $bouwsteen = '';
        $templateId = '';
        //controleer de template Ids of het over MA's gaat

        if (!empty($bouwsteenData['substanceAdministration']['templateId'])) {

            if (is_array($bouwsteenData['substanceAdministration']['templateId'])) {

                if (!empty($bouwsteenData['substanceAdministration']['templateId'][0])) {
                    foreach ($bouwsteenData['substanceAdministration']['templateId'] as $templateIds) {
                        $templateId = $templateIds['@attributes']['root'];
                    }
                } else {
                    $templateId = $bouwsteenData['substanceAdministration']['templateId']['@attributes']['root'];
                }
            }
            if (isset($this->templateIds[$templateId]))
                $bouwsteen = $this->templateIds[$templateId];
        }
        if (!empty($bouwsteenData['supply']['templateId'])) {
            if (is_array($bouwsteenData['supply']['templateId'])) {
                if (!empty($bouwsteenData['supply']['templateId'][0])) {
                    foreach ($bouwsteenData['supply']['templateId'] as $templateIds) {
                        $templateId = $templateIds['@attributes']['root'];

                    }
                } else {
                    $templateId = $bouwsteenData['supply']['templateId']['@attributes']['root'];
                }
            }
            if (isset($this->templateIds[$templateId]))
                $bouwsteen = $this->templateIds[$templateId];
        }

        if ($templateId!='' && $bouwsteen == '') {
            echo "\n het templateID $templateId is niet gespecificeerd!\n\n";
            // print_r($bouwsteenData);
            return;
        }
        // echo $templateId;
        switch ($bouwsteen) {
            case 'MA':
            case 'TA':

                $bouwsteenDefinitie = $this->getTherapeutischeData($bouwsteenData,$bouwsteen);
                break;

            case 'MVE' :
                $this->getLogistiekeData($bouwsteenData,$bouwsteen);
                break;

            case 'MGB':
                $this->getMedicatieGebruikData($bouwsteenData,$bouwsteen);
                break;
        }


    }

    private function getTherapeutischeData($bouwsteenData,$bouwsteen) {

        $parsedData = array();
        $id = $bouwsteenData['substanceAdministration']['id']['@attributes']['root'] . '#' . $bouwsteenData['substanceAdministration']['id']['@attributes']['extension'];
        // echo "\n$idMA\n";

        $parsedData['id'] = $id;
        $parsedData['beschikbaar']=1;
        $parsedData['typeBouwsteen'] = $bouwsteen;

        $stopType = $this->getStopType($bouwsteenData['substanceAdministration']);
        $parsedData['stopCode'] = $stopType['stopCode']??'';


        $afspraakDatumTijd = $bouwsteenData['substanceAdministration']['author']['time']['@attributes']['value'];
        $parsedData['afspraakDatumTijd'] = $afspraakDatumTijd;


        $parsedData['beschikbaar']=($afspraakDatumTijd>$this->checkDatum?0:1);

        $geneesMiddel = $this->getGeneesMiddelFromBS($bouwsteenData['substanceAdministration']['consumable']??'');
        $parsedData['geneesMiddel'] = $geneesMiddel;

        //Zet de generieke MBH op de regel
        $parsedData['generiekeMBH'] = $this->setGeneriekeMBH($geneesMiddel);

        $omschrijvingDosering =  $this->getDoseerInfo($bouwsteenData['substanceAdministration']['text']??'');
        $parsedData['omschrijvingDosering']=$omschrijvingDosering;

        $datumStartStop = $this->parseEffectiveTime($bouwsteenData['substanceAdministration']['effectiveTime']??'');
        $parsedData['start'] = $datumStartStop['low'];
        $parsedData['stop'] = $datumStartStop['high'];

        if ($datumStartStop['fout']) {
            echo $id;
        }

        $mbhID = $this->getMBHId($bouwsteenData['substanceAdministration']);
        $parsedData['mbhID'] = $mbhID;

        $relaties = $this->getRelaties($bouwsteenData['substanceAdministration']);
        $parsedData['relaties'] = $relaties;

       # $author = $this->getAuthorInfo($bouwsteenData['substanceAdministration']);

        $this->parsedData[$id] = $parsedData;

    }

    private function getLogistiekeData($bouwsteenData,$bouwsteen) {

        $parsedData = array();
        $id = $bouwsteenData['supply']['id']['@attributes']['root'] . '#' . $bouwsteenData['supply']['id']['@attributes']['extension'];
        // echo "\n $idMA \n";

        $parsedData['id'] = $id;
        $parsedData['typeBouwsteen'] = $bouwsteen;

        // $stopType = $this->getStopType($bouwsteenData['substanceAdministration']);
        // $parsedData['stopType'] = $stopType;

        $registratieDatumTijd = $bouwsteenData['supply']['effectiveTime']['@attributes']['value'];
        $parsedData['afspraakDatumTijd'] = $registratieDatumTijd;

        $parsedData['beschikbaar']=($registratieDatumTijd>$this->checkDatum?0:1);

        $geneesMiddel = $this->getGeneesMiddelFromBS($bouwsteenData['supply']['product']);
        $parsedData['geneesMiddel'] = $geneesMiddel;

        //Zet de generieke MBH op de regel
        $parsedData['generiekeMBH'] = $this->setGeneriekeMBH($geneesMiddel);

        ['code']['@attributes']['displayName']??'';
        $parsedData['geneesMiddel'] = $geneesMiddel;

        $mbhID = $this->getMBHId($bouwsteenData['supply']);
        $parsedData['mbhID'] = $mbhID;

        $relaties = $this->getRelaties($bouwsteenData['supply']);
        $parsedData['relaties'] = $relaties;

        $this->parsedData[$id] = $parsedData;

    }

    private function getMedicatieGebruikData($bouwsteenData,$bouwsteen) {
        // echo '<pre>';
        // print_r($bouwsteenData);
        // echo '</pre>';
        $parsedData = array();
        $id = $bouwsteenData['substanceAdministration']['id']['@attributes']['root'] . '#' . $bouwsteenData['substanceAdministration']['id']['@attributes']['extension'];
        // echo "\n$idMA\n";

        $parsedData['id'] = $id;
        $parsedData['beschikbaar']=1;
        $parsedData['typeBouwsteen'] = $bouwsteen;

        $stopType = $this->getStopType($bouwsteenData['substanceAdministration']);
        $parsedData['stopCode'] = $stopType['stopCode']??'';


        $afspraakDatumTijd = $bouwsteenData['substanceAdministration']['author']['time']['@attributes']['value'];
        $parsedData['afspraakDatumTijd'] = $afspraakDatumTijd;


        $parsedData['beschikbaar']=($afspraakDatumTijd>$this->checkDatum?0:1);

        $geneesMiddel = $this->getGeneesMiddelFromBS($bouwsteenData['substanceAdministration']['consumable']??'');
        $parsedData['geneesMiddel'] = $geneesMiddel;

        //Zet de generieke MBH op de regel
        $parsedData['generiekeMBH'] = $this->setGeneriekeMBH($geneesMiddel);

        $omschrijvingDosering =  $this->getDoseerInfo($bouwsteenData['substanceAdministration']['text']??'');
        $parsedData['omschrijvingDosering']=$omschrijvingDosering;

        $datumStartStop = $this->parseEffectiveTime($bouwsteenData['substanceAdministration']['effectiveTime']??'');
        $parsedData['start'] = $datumStartStop['low'];
        $parsedData['stop'] = $datumStartStop['high'];

        if ($datumStartStop['fout']) {
            echo $id;
        }

        $mbhID = $this->getMBHId($bouwsteenData['substanceAdministration']);
        $parsedData['mbhID'] = $mbhID;

        $relaties = $this->getRelaties($bouwsteenData['substanceAdministration']);
        $parsedData['relaties'] = $relaties;

       # $author = $this->getAuthorInfo($bouwsteenData['substanceAdministration']);

        $this->parsedData[$id] = $parsedData;

    }

    private function setGeneriekeMBH($geneesMiddel) {
            /*
            • Generieke MBH-id: De generieke MBH-id wordt zodanig gegeneerd dat systemen onafhankelijk van elkaar
            dezelfde identificatie genereren voor medicatiebouwstenen
            met dezelfde prescriptiecode (PRK) of, indien PRK niet bekend is, de handelsproductcode (HPK).
            De generieke MBH-id gebaseerd op PRK bestaat uit een algemene OID-root ‘generiekeMBHIdPRK’ (uitgegeven door Nictiz: 2.16.840.1.113883.2.4.3.11.61.2),
            en de PRK in de OID-extension.
            De generieke MBH-id gebaseerd op HPK bestaat uit een algemene OID-root ‘generiekeMBHIdHPK’ (uitgegeven door Nictiz: 2.16.840.1.113883.2.4.3.11.61.3),
            en de HPK in de OID-extension.
            Een generieke MBH-id is alleen uniek binnen een patiënt, maar niet uniek over patiënten heen.
            Deze variant MBH-id is alleen bedoeld als tijdelijke oplossing in de transitiefase voor bepaalde situaties.

            */
        // print_r($geneesMiddel);
        switch ($geneesMiddel['type']??'') {
            case 'PRK' :
                $gMBH = '2.16.840.1.113883.2.4.3.11.61.2#' . $geneesMiddel['code'];
                break;
            case 'HPK' :
                $gMBH = '2.16.840.1.113883.2.4.3.11.61.3#' . $geneesMiddel['code'];
                break;

            default:
                $gMBH = '';
        }
        return $gMBH;

    }

    private function parseEffectiveTime($effectiveTime) {
        $low = '';
        $high = '';
        $fout=0;
        $timeUnit = array('uur'=>'hour', 'dag'=>'day','d'=>'day','wk'=>'week','jaar'=>'year');
        // print_r($effectiveTime);
        if ($effectiveTime=='') {
            echo "LET OP GEEN Effective Time: ";
            $fout=1;
        }
        if (is_array($effectiveTime)) {
            if (isset($effectiveTime['@attributes']['value'])) {
                $low = $hight = $effectiveTime['@attributes']['value'];

            }

            if (isset($effectiveTime['low'])) {
                if (isset($effectiveTime['low']['@attributes']['nullFlavor'])) {
                    $low = '';
                } else {
                    $low = $effectiveTime['low']['@attributes']['value'];
                }
            }
            if (isset($effectiveTime['high'])) {
                // print_r($effectiveTime);
                if (isset($effectiveTime['high']['@attributes']['nullFlavor'])) {
                    $high = '';
                } else {
                    $high = $effectiveTime['high']['@attributes']['value'];
                }
            }



            if (isset($effectiveTime['width'])) {
                $width = $effectiveTime['width']['@attributes']['value'];
                $unit = $effectiveTime['width']['@attributes']['unit']??'dag';
                $unitAdd = $timeUnit[$unit];
                // echo "\width: $width";
                if ($low) {
                    $high = date('YmdHis',strtotime($low . ' +' . $width . ' '. $unitAdd . ' -1 second'));
                } elseif ($high) {
                    $low = date('YmdHis',strtotime($low . ' -' . $width . ' '. $unitAdd . ' +1 second'));
                }

                // echo "\nLOW + WIDTH: $low";
                // echo "\nHIGH + WIDTH: $high";



            }
        }

        // echo "\nLOW : \t $low";
        // echo "\nHIGH : \t $high";

        if ($low == '')
            $low = '20200101000000';
        if ($high == '')
            $high = '30991231000000';

        return array('low'=>$low,'high'=>$high,'fout'=>$fout);

    }

    private function getGeneesMiddelFromBS($consumable) {
        // echo '<pre>';
        // print_r($consumable);
        // echo '</pre>';
        if ($consumable=='')
            return 'Geen geneesmiddel';
        $naam = $consumable['manufacturedProduct']['manufacturedMaterial']['code']['@attributes']['displayName']??'';
        if ($naam=='')
            $naam = $consumable['manufacturedProduct']['manufacturedMaterial']['name'];

        $code = $consumable['manufacturedProduct']['manufacturedMaterial']['code']['@attributes']['code']??'';
        $codeSystem = $consumable['manufacturedProduct']['manufacturedMaterial']['code']['@attributes']['codeSystem']??'';
        $type = '';
        switch ($codeSystem) {
            case '2.16.840.1.113883.2.4.4.10':
                $type = 'PRK';
                break;

            case '2.16.840.1.113883.2.4.4.1':
                $type = 'GPK';
                break;

            case '2.16.840.1.113883.2.4.4.7':
                $type = 'HPK';
                break;

            case '2.16.840.1.113883.2.4.4.8':
                $type='ZI';
                break;

            default:
                $type = 'local';
        }

        return array('naam'=>$naam,'code'=>$code,'type'=>$type);

    }




    private function getMBHId($bouwsteenData) {
        $entryRelationships = $bouwsteenData['entryRelationship'];
        foreach ($entryRelationships as $entryRelationship) {
            if (!empty($entryRelationship['procedure'])) {
                $templateId = $entryRelationship['procedure']['templateId']['@attributes']['root'];
                if ($templateId == '2.16.840.1.113883.2.4.3.11.60.20.77.10.9084') {
                    $mbhID = $entryRelationship['procedure']['id']['@attributes']['root'] . '#' . $entryRelationship['procedure']['id']['@attributes']['extension'];
                    return $mbhID;
                }
            }
        }

        return '';
    }

    private function getStopType($bouwsteenData) {
        $entryRelationships = $bouwsteenData['entryRelationship'];
        foreach ($entryRelationships as $entryRelationship) {
            if (!empty($entryRelationship['observation'])) {
                $templateId = $entryRelationship['observation']['templateId']['@attributes']['root']??'';
                //het kan een array zijn, maar dat is niet het stop type
                if ($templateId == '2.16.840.1.113883.2.4.3.11.60.20.77.10.9414') {
                    $stopType = $entryRelationship['observation']['value']['@attributes']['code'];
                    $stopTypeDisplay = $entryRelationship['observation']['value']['@attributes']['displayName'];
                    return array('stopType'=>$stopType,'stopTypeDisplay'=>$stopTypeDisplay,'stopCode'=>strtoupper($stopTypeDisplay[0]));
                }

            }
        }
        return '';
    }

    private function getRelaties($bouwsteenData) {
        $relaties = array();
        $entryRelationships = $bouwsteenData['entryRelationship'];
        // print_r($entryRelationships);
        // print_r(array_keys($entryRelationships));
        if (array_key_first($entryRelationships) !== 0) {
            //Het is geen array van relationships, maar een enkelvoudige
            $relatie = $this->getRelatieInfo($entryRelationships);
            if ($relatie !== false)
                $relaties[]=$relatie;
        }

        foreach ($entryRelationships as $entryRelationship) {
            $relatie = $this->getRelatieInfo($entryRelationship);
            if ($relatie !== false)
                $relaties[]=$relatie;

        }
        return $relaties;

    }

    private function getRelatieInfo($entryRelationship) {
        $relatie = array();
        if (!isset($entryRelationship['@attributes'])) {
            return false;
        }

        if (!isset($entryRelationship['@attributes']['typeCode'])) {
            return false;
        }

        // print_r($entryRelationship);
        if ($entryRelationship['@attributes']['typeCode']!= "REFR") {
            //het is geen relatie naar andere bousteen
            // print_r($bouwsteenData);
            return false;
        }


        if (!empty($entryRelationship['substanceAdministration'])) {
            $relatie['soortRelatieCode'] = $entryRelationship['substanceAdministration']['code']['@attributes']['code'];
            $relatie['soortRelatieMemoCode'] = $this->bouwsteenCodes[$relatie['soortRelatieCode']]??'ONBEKEND';
            $relatie['soortRelatieText'] = $entryRelationship['substanceAdministration']['code']['@attributes']['displayName'];
            $relatie['ID'] = ($entryRelationship['substanceAdministration']['id']['@attributes']['root']??'') . '#' . ($entryRelationship['substanceAdministration']['id']['@attributes']['extension']??'');
        }

        if (!empty($entryRelationship['supply'])) {
            $relatie['soortRelatieCode'] = $entryRelationship['supply']['code']['@attributes']['code'];
            $relatie['soortRelatieMemoCode'] = $this->bouwsteenCodes[$relatie['soortRelatieCode']]??'ONBEKEND';
            $relatie['soortRelatieText'] = $entryRelationship['supply']['code']['@attributes']['displayName'];
            $relatie['ID'] = $entryRelationship['supply']['id']['@attributes']['root'] . '#' . $entryRelationship['supply']['id']['@attributes']['extension'];
        }
        return $relatie;


    }

    private function getDoseerInfo($info) {
        // echo '<pre>';
        $arr = explode(',',$info);
        // print_r($arr);
        foreach ($arr as $id=>$line) {
            $line = ' ' . strtolower($line);
            if (strpos($line,'vanaf')!==false) {
                $arr[$id]='';
            }
            if (strpos($line,'tot en')!==false) {
                $arr[$id]='';
            }
            // if (strpos($line,'gedurende')!==false) {
            //     $arr[$id]='';
            // }
            if (strpos($line,'oraal')!==false) {
                $arr[$id]='';
            }
        }
        // print_r($arr);
        return trim(implode('',$arr));
    }

    private function getAuthorInfo($bouwsteenData) {
        $authorArr = $bouwsteenData['author'];
        $type = $authorArr['assignedAuthor']['code']['@attributes']['code'];
        $name = $authorArr['assignedAuthor']['assignedPerson']['name']['family'];
        // print_r($authorArr);

    }

    private function setToZero() {
        $IDS = array();
        $IDS['MA']=0;
        $IDS['TA']=0;
        $IDS['WDS']=0;
        $IDS['MGB']=0;
        $IDS['MVE']=0;
        return $IDS;
    }

    private function setSimpleIds() {
        uasort($this->parsedData, array($this,'sorteerChronologisch'));
        $mbhIDSimple = 0;
        $mbhID = '';
        $xmlMBHID = '';
        $maID = '';
        $taID = '';
        $IDS = $this->setToZero();

        // echo "\nPARSED DATA\n";
        // print_r($this->parsedData);

        foreach ($this->parsedData as $id=>$data) {
            if ($xmlMBHID!=$data['mbhID']) {
                $mbhIDSimple++;
                $IDS = $this->setToZero();
                $IDSimple = $this->setToZero();
                $xmlMBHID = $data['mbhID'];
            }
            $typeBouwsteen = $data['typeBouwsteen'];
            $IDS[$typeBouwsteen]++;
            $this->parsedData[$id]['simpelID'] = $mbhIDSimple . '_' . $IDS[$typeBouwsteen];
        }
    }


    private function sorteerChronologisch($a,$b) {

        if ($a['mbhID'] < $b['mbhID'])
            return -1;
        if ($a['mbhID'] > $b['mbhID'])
            return 1;

        if ($a['afspraakDatumTijd'] < $b['afspraakDatumTijd'])
            return -1;

        if ($a['afspraakDatumTijd'] > $b['afspraakDatumTijd'])
            return 1;

        if (($a['start']??'') < ($b['start']??''))
            return -1;
        if (($a['start']??'') > ($b['start']??''))
            return 1;

        if (($a['stop']??'') < ($b['stop']??''))
            return -1;
        if (($a['stop']??'') > ($b['stop']??''))
            return 1;

    }

}



// print_r($array);
?>

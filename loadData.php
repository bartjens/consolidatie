<?php
include_once('parseXML.php');
class fileHandler {

    private $parsedData = array();
    private $fileType = '';
    private $filePath = '';
    private $rawXML = '';
    private $fileName = '';


    public function __construct() {


    }

    public function getParsedData() {
        return $this->parsedData;
    }

    public function getFileType() {
        return $this->fileType;
    }

    public function getFileName() {
        return $this->fileName;
    }

    public function getRawXML() {
        return $this->rawXML;
    }

    public function run() {
        $array = array();
        if (!empty($_GET)) {
            // print_r($_GET);
            $fileName = $_GET['fileName']??'';
            $makeJson = $_GET['makeJson']??0;
            $this->fileType = $_GET['fileType']??'';
            $userID=$_GET['userID']??'';
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

            $checkDatum = date('Ymd000000',strtotime($_GET['checkDatum']??''));
            // echo '<h2>' . date('d-M-Y',strtotime($_GET['checkDatum'])) . '</h2>';

            if ($fileName=='')
                die('');

            $this->fileName = $fileName;

            if ($fileExtension=='xml') {
                // $this->fileType = 'sxmlv3';

                // echo $fileName;
                if (!is_file($fileName)) {
                    echo $fileName;
                    die('Naughty');
                }

                $this->loadXmlFile($fileName,$checkDatum);
                // print_r($this->parsedData);

                // $xmlData = file_get_contents('data/' . $fileName);


                if ($makeJson == 1) {
                    $datFilename = substr($fileName,0,-3). 'json';
                    $jsonParsedData = json_encode($this->parsedData, JSON_PRETTY_PRINT);
                    file_put_contents('simpledata/'.$datFilename,$jsonParsedData);
                }

            } elseif ($fileExtension == 'json') {
                if (!is_file($fileName))
                    die('Naughty');
                $this->fileType = 'parsedjson';
                $jsonData = file_get_contents($fileName);

                $this->parsedData = json_decode($jsonData,true);
                // print_r($parsedData);
            } else {
                die('Nope!');
            }
        } else {
            if (!empty($_POST)) {
                if (($_POST['upload']??0)==1) {
                    $this->fileType = 'uxmlv3';
                    $this->uploadFile();

                } else {
                // print_r($_POST);
                    $this->parsedData = array();
                    $this->fileType = 'mojson';
                    $jsonData = $_POST['jsonData']['data'];
                    $checkDatum = date('Ymd000000',strtotime($_POST['jsonData']['checkDatum']));


                    // echo '<pre>';
                    // print_r($jsonData);
                    if ($jsonData!='') {
                        $this->parsedData = json_decode($jsonData,true);
                    } else {
                        die();
                    }
                }
            } else {
                die('Nope!');
            }

        }


    }

    function loadXmlFile($fileName,$checkDatum) {
        // echo $fileName;
        $sourceIsLSP = false;
        $messageIsMCCI = false;
        $xmlString = '';
        $xmlParts = array();
        $fp = fopen($fileName,'r');

        if ($fp) {
            while (($buffer = fgets($fp, 4096)) !== false) {
                // echo $buffer;
                if (    strpos(' ' . $buffer,'<SOAP-ENV:Envelope')
                    || strpos(' ' . $buffer,'<SOAP-ENV:Body')
                    || strpos(' ' . $buffer,'<SOAP-ENV:Header')
                    || strpos(' ' . $buffer,'</SOAP-ENV:Header')
                    || strpos(' ' . $buffer,'</SOAP-ENV:Body>')
                        || strpos(' ' . $buffer,'</SOAP-ENV:Envelope>') ) {
                    $sourceIsLSP = true;
                    continue;
                }



                if (strpos($buffer, 'MCCI')) {
                    $messageIsMCCI = true;
                }

                if (strpos($buffer,'?xml'))
                    continue;


                $xmlString .= $buffer;
            }
            if (!feof($fp)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($fp);
        }
        $type='';
        if ($messageIsMCCI) {
            $type='mcci';
        } else if ($sourceIsLSP) {
            $type='lsp';
        }
        // $xmlString = $this->convertXML2Array($xmlString,$type);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
        $this->rawXML = $xml;

        if ($xml === false) {
            echo "Failed loading XML\n";
            foreach(libxml_get_errors() as $error) {
                echo "\t", $error->message;
            }
        }

        $json = json_encode($xml);
        $array = json_decode($json,TRUE);

        // print_r($array);
        $arrData = array();
        $xmlParser = new parseXML($checkDatum);
        if ($messageIsMCCI) {
            foreach ($array as $key=>$item) {
                // echo "\n$key";
                if (strpos(' ' . $key,'QU')) {
                    // print_r($item);
                    if (!empty($item['ControlActProcess']['subject']['organizer']))
                        $arrData[]=$item['ControlActProcess']['subject']['organizer'];
                    else {
                        //het is een array
                        foreach ($item as $msg) {
                            if (!empty($msg['ControlActProcess']['subject']['organizer']))
                                 $arrData[]=$msg['ControlActProcess']['subject']['organizer'];
                            // else {
                            //     Er was een foutmelding in het antwoord
                            // }
                        }
                    }
                }

            }

        } elseif ($sourceIsLSP) {
            //haal alleen de organiser eruit
            $arrData[] = $array['ControlActProcess']['subject']['organizer'];
        } else {
            $arrData[] = $array;
        }
        $this->parsedData = $xmlParser->getDataFromArray($arrData);


        // print_r($this->parsedData);
    }

    function uploadFile() {
        $returnData = array();
        // print_r($_FILES);
        // print_r($_POST);
        $userID = $_POST['userID'];
        $uploadData = $_FILES["filename"];
        if (($uploadData['type']??'')!='text/xml')
            die('not a good file') ;

        $ext  = pathinfo($uploadData['name'], PATHINFO_EXTENSION);
        if ($ext != 'xml')
            die('not a good file') ;

        // [filename] => Array
        // (
        //     [name] => mg-mp-mg-tst-CONS-MA-Scenarioset12-v30-12-1.xml
        //     [full_path] => mg-mp-mg-tst-CONS-MA-Scenarioset12-v30-12-1.xml
        //     [type] => text/xml
        //     [tmp_name] => C:\PHP\temp\php4822.tmp
        //     [error] => 0
        //     [size] => 116172
        // )
        $checkDatum = date('Ymd000000');

        $target_dir = "uploads/" . $userID . '/';
        if (!is_dir($target_dir)) {
            mkdir($target_dir);
        }
        $target_file = $target_dir . basename($uploadData["name"]);
        if (move_uploaded_file($uploadData["tmp_name"], $target_file)) {
            // $this->fileName = $target_file;
            $this->loadXmlFile($target_file,$checkDatum);
            // $returnData['filename'] = $target_file;
            // $returnData['parsedData'] = $parsedData;
            $this->fileName = $target_file;


          } else {
                echo "Sorry, there was an error uploading your file.";
          }
        $uploadOk = 1;
        // return $returnData;



    }

    private function convertXML2Array($xmlString,$type) {
        $xmlResult = '';
        // echo htmlspecialchars($xmlString);
        $xmlBerichtDomDoc = new DOMDocument();
		$xmlBerichtDomDoc->preserveWhiteSpace = false;
		$xmlBerichtDomDoc->formatOutput = true;
		$xmlBerichtDomDoc->loadXML($xmlString);
		$xmlString = $xmlBerichtDomDoc->saveXML();

		// $this->xpath = new DOMXPath($this->xmlBerichtDomDoc);


        if ($type=='mcci') {

            // $organizers = $this->xpath->query('//*/organizer');
            // print_r($organizers);

        } elseif ($type=='lsp') {

        } else {

        }

        return $xmlResult;
    }
}
?>
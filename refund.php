<?php

require dirname(__FILE__) . '/PHPMailer/PHPMailerAutoload.php';

// change this to switch between test/live
$environment = 'live';

if ($environment == 'live') {
    // LIVE connection data for connecting to Shopify API and Realex 
    // Shopify API
    $shopUrl = '#';
    $apiKey = '#';
    $password = '#';
    define('SHOPIFY_APP_SECRET', '#');   // used for webhook
    // Realex 
    $merchantid = "#";
    $secret = "#";
    $account = "#";
    $refundPass = "#";
} else {
    // TEST connection data for connecting to Shopify API and Realex
    // Shopify API
    $shopUrl = '#';
    $apiKey = '#';
    $password = '#';
    define('SHOPIFY_APP_SECRET', '#');   // used for webhook
    // Realex 
    $merchantid = "#";
    $secret = "#";
    $account = "#";
    $refundPass = "#";
}

function verify_webhook($data, $hmac_header) {
    $calculated_hmac = base64_encode(hash_hmac('sha256', $data, SHOPIFY_APP_SECRET, true));
    return ($hmac_header == $calculated_hmac);
}

$hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
$refundData = file_get_contents('php://input');
$verified = verify_webhook($refundData, $hmac_header);

// only process verified request
if ($verified) {
    $data = json_decode($refundData, true);

    $orderid = $data['order_id'];

    $logData = "\r\n=============== Start Refund of Order: $orderid ===============\r\n";
    $logData .= "Webhook Data: " . $refundData . "\r\n";

    // as there is no refund amount field in Shopify admin for Realex gateway, use 'Reason for refund field' to set this value
    $parsedNote = explode('-', $data['note']);
    $amount = str_replace(".", "", trim($parsedNote[0]));
    $comment1 = trim($parsedNote[1]);
    $comment2 = 'Rebate of order ID: ' . $orderid;

    // get Transactions data from Shopify
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://$apiKey:$password@$shopUrl/admin/orders/$orderid/transactions.json");
    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); //This should always be set to 'TRUE' when in production to ensure the SSL is enabled.
    $orderResponse = curl_exec($ch);
    curl_close($ch);

    $orderData = json_decode($orderResponse, true);
    $logData .= "Transaction response from Shopify: " . $orderResponse . "\r\n";

    // check if there are any transactions
    if (!(empty($orderData['transactions']))) {

        foreach($orderData['transactions'] as $trans){
			
			if($trans['status'] == 'success' && isset($trans['receipt']['authcode'])){
				$authcode = $trans['receipt']['authcode'];
				$pasref = $trans['receipt']['pasref'];
				$realexOrderId = $trans['receipt']['orderid'];
				$currency = $trans['currency'];
				$gateway = $trans['gateway'];
				break;
			}
		}

        // only process Realex transactions
        if ($gateway == 'realex') {
            
            //Initialise arrays
            $parentElements = array();
            $TSSChecks = array();
            $currentElement = 0;
            $currentTSSCheck = "";

            $timestamp = strftime("%Y%m%d%H%M%S");
            mt_srand((double) microtime() * 1000000);

            // This section of code creates the md5hash that is needed
            $cardnumber = '';
            $tmp = "$timestamp.$merchantid.$realexOrderId.$amount.$currency.$cardnumber";
            $md5hash = md5($tmp);
            $tmp = "$md5hash.$secret";
            $md5hash = md5($tmp);
            $refundhash = sha1($refundPass);

            // Create and initialise XML parser
            $xml_parser = xml_parser_create();
            xml_set_element_handler($xml_parser, "startElement", "endElement");
            xml_set_character_data_handler($xml_parser, "cDataHandler");

            // TODO: remove this test request
//        $realexOrderId = '#';
//        $pasref = '#';
//        $authcode = '#';
//        $currency = '#';
//        $amount = #;
            //A number of variables are needed to generate the request xml that is sent to Realex Payments.
            $xml = "<request type='rebate' timestamp='$timestamp'>
	<merchantid>$merchantid</merchantid>
	<account>$account</account>
	<orderid>$realexOrderId</orderid>
        <pasref>$pasref</pasref>
        <authcode>$authcode</authcode>
	<amount currency='$currency'>$amount</amount>
        <refundhash>$refundhash</refundhash>
        <autosettle flag='1' />
	<comments> 
            <comment id='1'>$comment1</comment>
            <comment id='2'>$comment2</comment>
	</comments>
	<md5hash>$md5hash</md5hash>
    </request>";

            $logData .= "Realex Request: " . $xml . "\r\n";

            // Send the request array to Realex Payments
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://epage.payandshop.com/epage-remote.cgi");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, "payandshop.com php version 0.9");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); //This should always be set to 'TRUE' when in production to ensure the SSL is enabled.
            $realexResponse = curl_exec($ch);
            curl_close($ch);

            //Tidy it up
            $realexResponse = eregi_replace("[[:space:]]+", " ", $realexResponse);
            $realexResponse = eregi_replace("[\n\r]", "", $realexResponse);

            /* THe "startElement()" function is called when an open element tag is found.
              It creates a variable on the fly contructed of all the parent elements
              joined together with an underscore. So the following xml:

              <response><something>Owen</something></response>
              would create two variables:
              $RESPONSE and $RESPONSE_SOMETHING
             */

            function startElement($parser, $name, $attrs) {
                global $parentElements;
                global $currentElement;
                global $currentTSSCheck;

                array_push($parentElements, $name);
                $currentElement = join("_", $parentElements);

                foreach ($attrs as $attr => $value) {
                    if ($currentElement == "RESPONSE_TSS_CHECK" and $attr == "ID") {
                        $currentTSSCheck = $value;
                    }

                    $attributeName = $currentElement . "_" . $attr;
                    // print out the attributes..
                    //print "$attributeName\n";

                    global $$attributeName;
                    $$attributeName = $value;
                }

                // uncomment the "print $currentElement;" line to see the names of all the variables you can 
                // see in the response.
                // print $currentElement;
            }

            /* The "cDataHandler()" function is called when the parser encounters any text that's 
              not an element. Simply places the text found in the variable that
              was last created. So using the XML example above the text "Owen"
              would be placed in the variable $RESPONSE_SOMETHING
             */

            function cDataHandler($parser, $cdata) {
                global $currentElement;
                global $currentTSSCheck;
                global $TSSChecks;

                if (trim($cdata)) {
                    if ($currentTSSCheck != 0) {
                        $TSSChecks["$currentTSSCheck"] = $cdata;
                    }

                    global $$currentElement;
                    $$currentElement .= $cdata;
                }
            }

//  The "endElement()" function is called when the closing tag of an element is found. 
//  Just removes that element from the array of parent elements.

            function endElement($parser, $name) {
                global $parentElements;
                global $currentTSSCheck;

                $currentTSSCheck = 0;
                array_pop($parentElements);
            }

// parse the response xml
            if (!xml_parse($xml_parser, $realexResponse)) {
                die(sprintf("XML error: %s at line %d", xml_error_string(xml_get_error_code($xml_parser)), xml_get_current_line_number($xml_parser)));
            }

            $logData .= "Realex Response:\r\n";
            $logData .= "\t- Timestamp: " . $RESPONSE_TIMESTAMP . "\r\n";
            $logData .= "\t- Result: " . $RESPONSE_RESULT . "\r\n";
            $logData .= "\t- Message: " . $RESPONSE_MESSAGE . "\r\n";
            $logData .= "=============== END Refund ===============\r\n";

            // send notification email
            // if refund has failed
            if ($RESPONSE_RESULT != 00) {

                $mail = new PHPMailer;

                $mail->isSMTP();                                      // Set mailer to use SMTP
                $mail->Host = '#';  // Specify main and backup SMTP servers
                $mail->SMTPAuth = true;                               // Enable SMTP authentication
                $mail->Username = '#';                 // SMTP username
                $mail->Password = '#';                           // SMTP password
                $mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
                $mail->Port = 587;                                    // TCP port to connect to

                $mail->From = '#';
                $mail->FromName = 'vavavoom.ie Refund';
                $mail->addAddress('info@vavavoom.ie');     // Add a recipient
				$mail->addCC('#');     
                $mail->addBCC('#');
				
                $mail->Subject = 'Failed - Automated Realex Refund for order #' . $orderid;
				$mailMessage = "Hello, ";
                $mailMessage .= "Refund of order #" . $orderid . " failed, please see the details from Realex: ";
                $mailMessage .= "Response result: " . $RESPONSE_RESULT . " ";
                $mailMessage .= "Message: " . $RESPONSE_MESSAGE . " ";
                $mail->Body = $mailMessage;

                if (!$mail->send()) {
					$logData .= "Email could not be sent.\r\n";
                    $logData .= "Mailer Error:" . $mail->ErrorInfo . "\r\n";
                }
            }

            // garbage collect the parser.
            xml_parser_free($xml_parser);
        }
    } else {
        $logData .= "Error: can`t process the refund as transaction data is not present for order: " . $data['order_id'] . ". Please check if the refund hasn`t been processed yet.\r\n";
    }

    $logFile = fopen("#", "a") or die("Unable to open file!");
    fwrite($logFile, $logData);
    fclose($logFile);
}
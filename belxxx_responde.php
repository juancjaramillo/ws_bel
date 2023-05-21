<?php 

require_once('validations.php');
require 'Slim/Slim.php';

$middleware_api_url = 'http://10.1.1.xxx/bexxx/middleware/api';
$middleware_api_token = 'Q1JNQmxx29ycAxxx';
$date_hour = date("Y-m-d H:i:s", strtotime('+5 hours'));

\Slim\Slim::registerAutoloader();
$ws = new \Slim\Slim();

$ws->response->headers->set("Content-type", "application/json;charset=ISO-8859-1");
$ws->response->headers->set('Access-Control-Allow-Origin', '*');
$ws->response->headers->set('Access-Control-Allow-Credentials', 'true');
$ws->response->headers->set('Access-Control-Allow-Headers', 'Authorization, X-Requested-With');

$ws->post('/setCases', 'postSetCases');
$ws->run();


function getConnection() {
	
	$dbhost = '10.1.xx.xx';
	$dbname = 'DB_crm_xxx';
	$dbuser = 'mloxxxo';
	$dbpass = 'sugxxxx';
	$dbh = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass, array(PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
	return $dbh;
}

function codificaJson($cadena){
	return utf8_decode(preg_replace_callback('/\\\\u([0-9a-f]{4})/i', 'replace_unicode_escape_sequence', json_encode($cadena)));
}


function replace_unicode_escape_sequence($match) {
	return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
}

function generate_uuid() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
		mt_rand( 0, 0xffff ),
		mt_rand( 0, 0x0fff ) | 0x4000,
		mt_rand( 0, 0x3fff ) | 0x8000,
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}

function purge_array($array) {
	$result = array();
    foreach($array as $key=>$value){
    	if (is_array($value)){
            $result = purge_array($value);
        } else {
        	if($value != 'true'){
        		array_push($result, $value);
        	}
        }
    }
	return $result;
}


function purge_array2($array) {
	$result = array();
    foreach($array as $key=>$value){
    	if (is_array($value)){
    		if(count($array[$key]) != 0){
    			$result[$key] = array($array[$key]);
    		}
        }
    }
    return $result;
}


function postSetCases() {
	try{
		
		$ws = \Slim\Slim::getInstance();
		$data = $ws->request()->post();
		$db = getConnection();
		
		//Validaciones
		$validations = array();
		if(isset($data['country']) ? $country = $data['country'] : $country = null);
		$validations['country'] = array(
			Validation::validate_required($country),
			Validation::validate_existence($country, $db, 'emt_countries', 'id', 'código del país')
		);
		
		if(isset($data['consultant_code']) ? $consultant_code = $data['consultant_code'] : $consultant_code = null);
		$validations['consultant_code'] = array(Validation::validate_required($consultant_code));
		
		if(isset($data['subject']) ? $subject = $data['subject'] : $subject = null);
		$validations['subject'] = array(Validation::validate_required($subject));
		
		if(isset($data['body']) ? $body = $data['body'] : $body = null);
		$validations['body'] = array(Validation::validate_required($body));
		
		if(isset($data['country']) && isset($data['consultant_code'])) {
			global $middleware_api_url, $middleware_api_token;
			$value[] = $data['country'];
			$value[] = $data['consultant_code'];
			$consultant = Validation::validate_existence_ws($value, $middleware_api_url, $middleware_api_token);
			if(isset($consultant['error'])){
				$validations['consultant_code'] = array($consultant);
			}
		}
		
		//Formatear array de errores
		$error = purge_array2(array_map("purge_array", $validations));
		
		if(count($error) > 0){
			echo '{"error": ' . codificaJson($error). '}';
			$ws->response->setStatus(400);
		}else {
			
			$sql = "SET autocommit=0;";
			$db->query($sql);
			
			// Consultar cuenta
			$consultant_data = json_decode($consultant['data']);
			$account_id = $consultant_data->Results[0]->AccountId;
			$result = Validation::validate_existence($account_id, $db, 'accounts', 'id', 'identificador');
			if($result != 1) {
				insertAccount($account_id, $db);
			}
			
			//Insertar caso
			$case_id = generate_uuid();
			insertCase($case_id, $account_id, $data, $db);
			
			$sql = "COMMIT;";
			$db->query($sql);
			
			//echo '{"respuesta": '.$case_id.'}';
			$ws->response->setStatus(200);
		}
	} catch (Exception $e){
		$sql = "ROLLBACK;";
		$db->query($sql);
		$ws->response->setStatus(500);
		echo '{"error": '.$e.'}';
		die();
	}
}

function insertAccount($account_id, $db) {
	try{
		
		global $date_hour;
		$sql = "INSERT INTO accounts (id, date_entered, date_modified, modified_user_id, created_by, deleted) VALUES ('{$account_id}', '{$date_hour}', '{$date_hour}', '1', '1', '0');";
		$db->query($sql);
		
		$sql = "INSERT INTO accounts_cstm (id_c) VALUES ('{$account_id}');";
		$db->query($sql);
		
	} catch (Exception $e){
		$ws->response->setStatus(500);
		echo '{"error": '.$e.'}';
		die();
	}
}

function insertCase($case_id, $account_id, $data, $db) {
	try{
		
		global $date_hour;
		$sql = "
		INSERT INTO cases 
			(id, name, date_entered, date_modified,	modified_user_id, created_by, description, deleted, assigned_user_id, status, account_id)
		VALUES
			('{$case_id}',
			'{$data['country']} >>',
			'{$date_hour}',
			'{$date_hour}',
			'1',
			'1',
			'{$data['body']}',
			'0',
			'1',
			'en_progreso',
			'{$account_id}');
		";
		$db->query($sql);
		
		$sql = "
		INSERT INTO cases_cstm
			(id_c, emt_countries_id_c, channel_c)
		VALUES
			('{$case_id}',
			'{$data['country']}',
			'bexxx_responde'
			);
		";
		$db->query($sql);
		
	} catch (Exception $e){
		$ws->response->setStatus(500);
		echo '{"error": '.$e.'}';
		die();
	}	
}
<?php 

require 'Slim/Slim.php';
require 'Pest/Pest.php';

\Slim\Slim::registerAutoloader();

$middleware_api_url = 'http://10.1.xx.xx/xx/middleware/api';
$middleware_api_token = 'Q1Jxxxxxxxxxxx';

$ws = new \Slim\Slim();  

$ws->response->headers->set("Content-type", "application/json;charset=ISO-8859-1");
$ws->response->headers->set('Access-Control-Allow-Origin', '*');
$ws->response->headers->set('Access-Control-Allow-Credentials', 'true');
$ws->response->headers->set('Access-Control-Allow-Headers', 'Authorization, X-Requested-With');

$ws->post('/bexxx_responde', 'postbexxxResponde');
$ws->run();


function getConnection() {
	
	$dbhost = '10.1.xx.xx';
	$dbname = 'DB_crm_xx';
	$dbuser = 'mxx';
	$dbpass = 'suxx';
	$dbh = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass, array(PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
	return $dbh;
}


function balancerAssigned($id_country, $id_group_responsible) {

	$db = getConnection();

	$sql = "
	SELECT
		users.id AS id_user
		,SUM(CASE WHEN cases.status != 'atendido' THEN 1 ELSE 0 END) AS num_cases
	FROM
		users
		INNER JOIN users_cstm ON users.id = users_cstm.id_c
		LEFT JOIN cases ON users.id = cases.assigned_user_id
	WHERE
		users.deleted = 0
		AND users.status = 'Active'
		AND users_cstm.emt_countries_id_c = '{$id_country}'
		AND users_cstm.emt_group_responsible_id_c = '{$id_group_responsible}'
		AND (cases.deleted = '0' OR cases.deleted IS NULL)
	GROUP BY
		users.id
	ORDER BY
		num_cases ASC
	;";

	$objResult = $db->query($sql);
	$arrData = $objResult->fetchAll(PDO::FETCH_OBJ);
	return $arrData[0]->id_user;
}


function getAnsDate($ans, $id_country) {
	
	$db = getConnection();
	
	$sql = "
	SELECT
		DATE_ADD(emt_ufnt_calcular_ans_bexxx(CURRENT_TIMESTAMP(),'{$ans}','N','1', '{$id_country}'), INTERVAL +5 HOUR) AS date_expiration
	;";
	
	$objResult = $db->query($sql);
	$arrData = $objResult->fetchAll(PDO::FETCH_OBJ);
	
	return $arrData[0]->date_expiration;
}


function getTypificationInfo($country, $typification_level_1, $typification_level_2) {
	
	$db = getConnection();
	
	$sql = "
	SELECT
		emt_typification_cases_configurator.ans AS ans,
		emt_countries.name AS country,
		emt_typification_cases_level_1.name AS typification_level_1,
		emt_typification_cases_level_2.name AS typification_level_2,
		emt_typification_cases_configurator.emt_group_responsible_id_c AS group_responsible_level_1
	FROM
		emt_typification_cases_configurator
		JOIN emt_typification_cases_level_1 ON emt_typification_cases_configurator.emt_typification_cases_level_1_id_c = emt_typification_cases_level_1.id
		JOIN emt_typification_cases_level_2 ON emt_typification_cases_configurator.emt_typification_cases_level_2_id_c = emt_typification_cases_level_2.id
		JOIN emt_countries ON emt_typification_cases_configurator.emt_countries_id_c = emt_countries.id
	WHERE
		emt_typification_cases_configurator.emt_countries_id_c = '{$country}'
		AND emt_typification_cases_configurator.emt_typification_cases_level_1_id_c = '{$typification_level_1}'
		AND emt_typification_cases_configurator.emt_typification_cases_level_2_id_c = '{$typification_level_2}'
		AND emt_typification_cases_configurator.active = 1
		AND emt_typification_cases_level_1.active = 1
		AND emt_typification_cases_level_2.active = 1
		AND emt_typification_cases_configurator.deleted = 0
		AND emt_typification_cases_level_1.deleted = 0
		AND emt_typification_cases_level_2.deleted = 0
	LIMIT 1
	;";
	
	$result = $db->query($sql);
	$typification_configurator = $result->fetchAll(PDO::FETCH_OBJ);
	return $typification_configurator[0];
}


function valiadatebexxxResponde($country, $consultant_code, $body, $subject) {
	
	$db = getConnection();
	
	$response = array();
	
	if($country == '' || $country == null) {
		$response['country'][] = 'El campo pais no pude ser nulo';
	} else {
		$sql = "
		SELECT 1
		FROM emt_countries
		WHERE id = '{$country}'
		;";
	
		$result = $db->query($sql);
		if($result->fetchColumn() == 0) {
			$response['country'][] = 'El país no existe';
		}
	}
	if($consultant_code == '' || $consultant_code == null) {
		$response['consultant_code'][] = 'El campo consultora no pude ser nulo';
	} else {
		global $middleware_api_url, $middleware_api_token;
		
		$headers = array(
			'AuthToken' => $middleware_api_token
		);
	
		$pest = new Pest($middleware_api_url);
		try {
			$data = array(
				'rows' => '1',
				'CountryCode' => $country,
				'ClientCode' => $consultant_code,
			);
			$consultants = $pest->get('/Consultants', $data, $headers);
		} catch (Pest_NotFound $e) {
			$response['consultant_code'][] = 'La consultora no existe';
		}
	}
	if($subject == '' || $subject == null) {
		$response['subject'][] = 'El campo asunto no pude ser nulo';
	}
	if($body == '' || $body == null) {
		$response['body'][] = 'El campo cuerpo no pude ser nulo';
	}
	
	return $response;
}


function postbexxxResponde() {
	
	$ws = \Slim\Slim::getInstance();
	$post = $ws->request()->post();
	
	$db = getConnection();
	$uuid = uniqid();
	
	$country 		 = (isset($post['country'])) 		 ? $post['country'] 	    : null;
	$consultant_code = (isset($post['consultant_code'])) ? $post['consultant_code'] : null;
	$body 			 = (isset($post['body'])) 			 ? $post['body'] 			: null;
	$subject 		 = (isset($post['subject'])) 		 ? $post['subject'] 		: null;
	
	$response = valiadatebexxxResponde($country, $consultant_code, $body, $subject);
	
	if(count($response) > 0) {
		$ws->response->setStatus(400);
		echo json_encode(array('error' => $response));
		die();
	}
	/*
	$typification_level_1 = '8b3aef58-cad8-6771-759d-583360384861';
	$typification_level_2 = 'ef6562b8-3020-39dc-761f-5833603856c4';
	$channel = 'bexxx_responde';
	
	$typification_configurator = getTypificationInfo($country, $typification_level_1, $typification_level_2);
	$name = $typification_configurator->country . '>>' . $typification_configurator->typification_level_1 . '>>' . $typification_configurator->typification_level_2;
	$ans = $typification_configurator->ans;
	$group_responsible_level_1 = $typification_configurator->group_responsible_level_1;
	$user_1 = balancerAssigned($country, $group_responsible_level_1);
	$date_expiration = getAnsDate($ans, $country);
	*/
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->beginTransaction();
	try {
		$sql = "
			INSERT INTO cases (
				id
				,name
				,date_entered
				,date_modified
				,modified_user_id
				,created_by
				,description
				,deleted
				,assigned_user_id
				,case_number
				,type
				,status
				,priority
				,resolution
				,work_log
				,account_id
				,state
				,contact_created_by_id
			) VALUES (
				'{$uuid}'
				,null
				,now()
				,now()
				,'1'
				,'1'
				,'{$body}'
				,0
				,null
				,default
				,null
				,'en_progreso'
				,null
				,null
				,null
				,'D0563419-0EB1-4636-BBE7-845BBF5B67A7'
				,'Open'
				,null
			)
		;";
		
		$result = $db->query($sql);
		
		$sql = "
		INSERT INTO cases_cstm (
			id_c
			,jjwg_maps_lng_c
			,jjwg_maps_lat_c
			,jjwg_maps_geocode_status_c
			,jjwg_maps_address_c
			,emt_typification_cases_level_1_id_c
			,emt_typification_cases_level_2_id_c
			,form_json_c
			,form_xml_c
			,form_values_c
			,emt_countries_id_c
			,ans_c
			,date_expiration_c
			,user_id_c
			,user_id1_c
			,channel_c
		) VALUES (
			'{$uuid}'
			,null
			,null
			,null
			,null
			,'{$typification_level_1}'
			,'{$typification_level_2}'
			,null
			,null
			,null
			,'{$country}'
			,'{$ans}'
			,'{$date_expiration}'
			,'{$user_1}'
			,null
			,'{$channel}'
		)
		;";
		
		$result = $db->query($sql);
		$db->commit();
	} catch (Exception $e) {
		
  		$db->rollBack();
  		$ws->response->setStatus(500);
  		echo json_encode(array('error' => array('non_field_error' => array('Error creando el caso'))));
		die();
	}
	
	$ws->response->setStatus(200);
	die();
}

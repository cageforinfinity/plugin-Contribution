<?php 
/**
* ContributionController
*/
class Contribution_IndexController extends Omeka_Controller_Action
{	
	public function init()
	{
		$this->session = new Zend_Session_Namespace('Contribution');
	}
		
	public function addAction()
	{
		$item = new Item;
		
		if($this->processForm($item))
		{
			$this->_redirect('contribution/consent');
		}else {
			return $this->renderContributeForm($item);
		}		
	}
	
	public function thankyouAction()
	{
		$this->render('contribution/thankyou.php');
	}
	
	protected function renderContributeForm($item)
	{
		if($type = $this->_getParam('type')) {
			switch ($type) {
				case 'Document':
					$partial = "_document";
					break;
				case 'Still Image':
				case 'Moving Image':
				case 'Sound':
					$partial = "_file";
					break;
				default:
					$partial = "_document";
					break;
			}

		}else {
			$partial = "_document";
		}
		
		Zend_Registry::set('contribution_partial', $partial);
		
		return $this->render('contribution/add.php', compact('item'));		
	}
	
	protected function createEntity()
	{
		$contrib = $_POST['contributor'];
		
		//Make an anonymous entity if they didn't give a name
		if(empty($contrib['first_name']) and empty($contrib['last_name']) and empty($contrib['email'])) {
			require_once 'Anonymous.php';
			$entity = new Anonymous;
		}else {
			require_once 'Person.php';
			$entity = new Person;
			$entity->setArray($contrib);
		}

		$sql = "INSERT INTO entities (first_name, last_name, email, `type`) VALUES (:first_name, :last_name, :email, '{$entity->type}')";
		
		//Drop down to PDO b/c Doctrine is dying for some unknown reason
		$conn = Doctrine_Manager::getInstance()->connection();
		
		try {
			$pass = array('first_name'=>$contrib['first_name'], 'last_name'=>$contrib['last_name'], 'email'=>$contrib['email']);
			$conn->exec($sql, $pass);
			
			$entity_id = $conn->lastInsertId();
			
		} catch (Exception $e) {
			var_dump( get_class($e) );
			var_dump( $e->getMessage() );exit;
		}

		$contributor = new Contributor;
		
		$contributor->setArray($_POST['contributor']);
		
		//Set the IP address and entity_id
		$contributor->ip_address = $_SERVER['REMOTE_ADDR'];
		$contributor->entity_id = $entity_id;
		
		if(!$contributor->trySave()) {
			$error = $this->getErrorMsg();
			
			$this->flash($error);
		}
		
		return Doctrine_Manager::getInstance()->getTable('Entity')->find($entity_id);
	}

	/**
	 * Validate and save the contribution to the DB, save the new item in the session
	 * then redirect to the consent form, 
	 * otherwise render the contribution form again
	 *
	 * @return void
	 **/
	protected function processForm($item)
	{		
		if(!empty($_POST)) {
			if(array_key_exists('pick_type', $_POST)) return false;
			
			try {
				//Manipulate the array that will be processed by commitForm()
				$clean = array();
				
				
				$clean['title'] = $_POST['title'];
				$clean['description'] = $_POST['description'];
				$clean['tags'] = $_POST['tags'];				
				
				//@todo Change how the creator/contributor info is set if we ever implement it as entity relationships
				//Right now it is either the contributor who posted the item or it is whatever is in the text field
				$clean['contributor'] = $_POST['contributor']['first_name'] . ' ' . $_POST['contributor']['last_name'];
				$clean['creator'] = $_POST['contributor_is_creator'] ? $clean['contributor'] : $_POST['creator'];
				
		/*
					Zend::dump( $clean );
				Zend::dump( $_POST );exit;
		*/	
				//Create an entity using the data provided on the form and pass it as an option to the commitForm() call
				
				$entity = $this->createEntity();
				$options = array();
				$options['entity'] = $entity;
				
				
				//Give the item the correct Type (find it by name, then assign)
				$type = Doctrine_Manager::getInstance()->getTable('Type')->findByName($_POST['type']);
				
				if(!$type) {
					throw new Exception( "Invalid type named {$_POST['type']} provided!");
				}
				
				$item->Type = $type;
				
				//Handle the metatext
					//Document text (if applicable)
					//Posting Consent
					//Submission Consent
				if(!empty($_POST['text'])) {
					$item->setMetatext('Text', $_POST['text']);
				}
				
				//At this point we should set the submission consent to No (just in case it doesn't make it to the page)
				$item->setMetatext('Submission Consent', 'No');
				
				//Don't trust the post content!
				if(!in_array($_POST['posting_consent'], array('Yes', 'No', 'Anonymously'))) {
					throw new Exception( 'Invalid posting consent given!' );
				}
				
				$item->setMetatext('Posting Consent', $_POST['posting_consent']);
				$item->setMetatext('Online Submission', 'Yes');
									
				if($item->commitForm($clean, true, $options)) {
					
					$item->setAddedBy($entity);
					//Put item in the session for the consent form to use
					$this->session->item_id = $item->id;
					$this->session->email = $_POST['contributor']['email'];
					return true;
				}else {
					return false;
				}	
				
				
			} catch (Exception $e) {
				echo debug_backtrace();exit;
				$this->flash($e->getMessage());
				return false;
			}
		}
		return false;
	}
	
	/**
	 * Final submission, add the consent info and redirect to a thank-you page
	 *
	 * @return void
	 **/
	public function submitAction()
	{		
		$session = $this->session;

		$item = $this->getTable('Item')->find($session->item_id);
		
		$item->rights = $_POST['rights'];
		
		$item->setMetatext('Submission Consent', $_POST['submission_consent']);
		
		$item->save();
		
		$this->sendEmailNotification($session->email, $item);
		
		unset($session->item_id);
		unset($session->email);
		
		$this->_redirect('contribution/thankyou');
	}
	
	protected function sendEmailNotification($email, $item)
	{
		$item_url = WEB_ROOT . DIRECTORY_SEPARATOR . 'items/show/' . $item->id;
		
		$body = "Thank you for your contribution to " . get_option('site_title') . ".  Your contribution has been accepted and will be preserved in the digital archive. For your records, the permanent URL for your contribution is noted at the end of this email. Please note that contributions may not appear immediately on the website while they await processing by project staff.
			
Contribution URL (pending review by project staff):\n\n\t$item_url";
		
		$title = "Your " . get_option('site_title') . " Contribution";
  		
		$header = "From: " . get_option('administrator_email') . "\r\n" . 'X-Mailer: PHP/' . phpversion();
		
		$res = mail( $email, $title, $body, $header);		
	}
	
	/**
	 * Add the body of the consent form to the rights field for the item, 
	 * if applicable.  Set Submission Consent metatext to the form value
	 *
	 * @return void
	 **/
	public function consentAction()
	{		
		$this->render('contribution/consent.php');
	}
}
 
?>

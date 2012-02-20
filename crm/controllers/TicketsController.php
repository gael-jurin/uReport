<?php
/**
 * @copyright 2012 City of Bloomington, Indiana
 * @license http://www.gnu.org/licenses/agpl.txt GNU/AGPL, see LICENSE.txt
 * @author Cliff Ingham <inghamn@bloomington.in.gov>
 */
class TicketsController extends Controller
{
	/**
	 * @param string $id
	 * @return Ticket
	 */
	private function loadTicket($id)
	{
		try {
			$ticket = new Ticket($id);
			return $ticket;
		}
		catch (Exception $e) {
			$_SESSION['errorMessages'][] = $e;
			header('Location: '.BASE_URL.'/tickets');
			exit();
		}
	}

	/**
	 * Provides ticket searching
	 */
	public function index()
	{
		if (userIsAllowed('tickets','add')) {
			$this->template->blocks['search-form'][] = new Block('tickets/addNewForm.inc');
		}
		$this->template->setFilename('search');
		$this->template->blocks['search-form'][] = new Block('tickets/searchForm.inc');

		// Build the search query
		if (TicketList::isValidSearch($_GET)) {
			// Create the report
			$report = (isset($_GET['report']) && $_GET['report']
						&& is_file(APPLICATION_HOME."/blocks/{$this->template->outputFormat}/tickets/reports/$_GET[report].inc"))
				? new Block("tickets/reports/$_GET[report].inc")
				: new Block('tickets/searchResults.inc');

			// Tell the report what fields we want displayed
			$_GET['fields'] = empty($_GET['fields'])
				? TicketList::$defaultFieldsToDisplay
				: $_GET['fields'];


			if ($this->template->outputFormat == 'html') {
				$this->template->blocks['search-results'][] = new Block('tickets/searchParameters.inc');
				$this->template->blocks['search-results'][] = new Block('tickets/customReportLinks.inc');
			}
			$this->template->blocks['search-results'][] = $report;
		}
	}

	/**
	 * @param GET ticket_id
	 */
	public function view()
	{
		$ticket = $this->loadTicket($_GET['ticket_id']);

		if (!$ticket->allowsDisplay(isset($_SESSION['USER']) ? $_SESSION['USER'] : 'anonymous')) {
			$_SESSION['errorMessages'][] = new Exception('noAccessAllowed');
			header('Location: '.BASE_URL.'/tickets');
			exit();
		}

		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/ticketInfo.inc',
			array('ticket'=>$ticket)
		);

		if (userIsAllowed('tickets', 'update') && $ticket->getStatus()!='closed') {
			$this->template->blocks['history-panel'][] = new Block(
				'tickets/actionForm.inc',
				array('ticket'=>$ticket)
			);
		}

		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory())
		);

		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array(
				'issueList'=>$ticket->getIssues(),
				'ticket'=>$ticket,
				'disableButtons'=>$ticket->getStatus()=='closed'
			)
		);

		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation(),'disableButtons'=>true)
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketLocationInfo.inc',
				array('ticket'=>$ticket)
			);

			$ticketList = new TicketList(array('location'=>$ticket->getLocation()));
			if (count($ticketList) > 1) {
				$this->template->blocks['location-panel'][] = new Block(
					'tickets/ticketList.inc',
					array(
						'ticketList'=>$ticketList,
						'title'=>'Other cases for this location',
						'filterTicket'=>$ticket,
						'disableButtons'=>true
					)
				);
			}
		}
	}

	/**
	 *
	 */
	public function add()
	{
		$ticket = new Ticket();
		$issue = new Issue();

		// Handle any Location choice passed in
		if (isset($_GET['location']) && $_GET['location']) {
			$ticket->setLocation($_GET['location']);
			$ticket->setAddressServiceData(AddressService::getLocationData($ticket->getLocation()));
		}

		// Handle any Person choice passed in
		if (isset($_REQUEST['person_id'])) {
			$person = new Person($_REQUEST['person_id']);
			$issue->setReportedByPerson($person);
		}

		// Handle any Category choice passed in
		if (isset($_REQUEST['category_id'])) {
			$category = new Category($_REQUEST['category_id']);
			if ($category->allowsPosting($_SESSION['USER'])) {
				$ticket->setCategory($_REQUEST['category_id']);
			}
			else {
				$_SESSION['errorMessages'][] = new Exception('noAccessAllowed');
				header('Location: '.BASE_URL);
				exit();
			}
		}

		// Handle any Department choice passed in
		// Choosing a department here will cause the assignment form
		// to pre-select that department's defaultPerson
		if (isset($_GET['department_id'])) {
			try {
				$currentDepartment = new Department($_GET['department_id']);
			}
			catch (Exception $e) {
			}
		}
		// If they haven't chosen a department, start by assigning
		// the ticket to the current User, and use the current user's department
		if (!isset($currentDepartment)) {
			$ticket->setAssignedPerson($_SESSION['USER']);

			$dept = $_SESSION['USER']->getDepartment();
			$currentDepartment = new Department((string)$dept['_id']);
		}

		// Process the ticket form when it's posted
		if (isset($_POST['ticket'])) {
			if (isset($_POST['assignedPerson'])) {
				$_POST['ticket']['assignedPerson'] = $_POST['assignedPerson'];
				$_POST['ticket']['notes'] = $_POST['notes'];
			}
			// Validate Everything and save
			try {
				$ticket->set($_POST['ticket']);
				$issue->set($_POST['issue']);
				$ticket->updateIssues($issue);
				$ticket->save();

				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}


		$this->template->setFilename('ticketCreation');
		$return_url = new URL($_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
		//-------------------------------------------------------------------
		// Location Panel
		//-------------------------------------------------------------------
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation(),'disableButtons'=>true)
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Cases Associated with this Location',
					'disableLinks'=>true
				)
			);
		}
		//-------------------------------------------------------------------
		// Person Panel
		//-------------------------------------------------------------------
		if (isset($person)) {
			$this->template->blocks['person-panel'][] = new Block(
				'people/personInfo.inc',
				array(
					'person'=>$person,
					'disableButtons'=>true
				)
			);
			$reportedTickets = $person->getTickets('reportedBy');
			if (count($reportedTickets)) {
				$this->template->blocks['person-panel'][] = new Block(
					'tickets/ticketList.inc',
					array(
						'ticketList'=>$reportedTickets,
						'title'=>'Reported Cases',
						'disableButtons'=>true,
						'disableLinks'=>true,
						'limit'=>10,
						'moreLink'=>BASE_URL."/tickets?reportedByPerson={$person->getId()}"
					)
				);
			}
		}
		//-------------------------------------------------------------------
		// Ticket Panel
		//-------------------------------------------------------------------
		$this->template->blocks['ticket-panel'][] = new Block('tickets/changeLocationButton.inc');
		$this->template->blocks['ticket-panel'][] = new Block('tickets/changePersonButton.inc');
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/addTicketForm.inc',
			array(
				'ticket'=>$ticket,
				'issue'=>$issue,
				'return_url'=>$return_url,
				'currentDepartment'=>$currentDepartment
			)
		);
	}

	/**
	 * @param REQUEST ticket_id
	 * @param REQUEST confirm
	 */
	public function delete()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		if (isset($_REQUEST['confirm'])) {
			$ticket->delete();
			header('Location: '.BASE_URL.'/tickets');
			exit();
		}

		$this->template->blocks[] = new Block(
			'confirmForm.inc',
			array('title'=>'Confirm Delete','return_url'=>$ticket->getURL())
		);
		$this->template->blocks[] = new Block(
			'tickets/ticketInfo.inc',
			array('ticket'=>$ticket,'disableButtons'=>true)
		);
	}

	/**
	 * @param REQUEST ticket_id
	 * @param GET department_id
	 */
	public function assign()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		// Handle any Department choice passed in
		if (isset($_GET['department_id'])) {
			try {
				$currentDepartment = new Department($_GET['department_id']);
			}
			catch (Exception $e) {
			}
		}
		if (!isset($currentDepartment)) {
			$dept = $_SESSION['USER']->getDepartment();
			$currentDepartment = new Department((string)$dept['_id']);
		}


		// Handle any stuff the user posts
		if (isset($_REQUEST['assignedPerson'])) {
			try {
				$ticket->setAssignedPerson($_REQUEST['assignedPerson']);

				// add a record to ticket history
				$history = new History();
				$history->setAction('assignment');
				$history->setEnteredByPerson($_SESSION['USER']);
				$history->setActionPerson($ticket->getAssignedPerson());
				$history->setNotes($_REQUEST['notes']);
				$ticket->updateHistory($history);

				$ticket->save();

				$history->sendNotification($ticket);

				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		// Display the view
		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'departments/chooseDepartmentForm.inc',
			array('currentDepartment'=>$currentDepartment)
		);
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/assignTicketForm.inc',
			array('ticket'=>$ticket,'currentDepartment'=>$currentDepartment)
		);
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory(),'disableButton'=>true)
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('ticket'=>$ticket,'issueList'=>$ticket->getIssues(),'disableButtons'=>true)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Other tickets for this location',
					'disableButtons'=>true,
					'filterTicket'=>$ticket
				)
			);
		}
	}

	/**
	 * @param REQUEST ticket_id
	 * @param REQUEST person_id
	 */
	public function refer()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);
		if (isset($_REQUEST['person_id'])) {
			try {
				$person = new Person($_REQUEST['person_id']);
			}
			catch (Exception $e) {
			}
		}

		// Handle any stuff the user posts
		if (isset($_POST['referredPerson'])) {
			try {
				$ticket->setReferredPerson($_POST['referredPerson']);

				// add a record to ticket history
				$history = new History();
				$history->setAction('referral');
				$history->setEnteredByPerson($_SESSION['USER']);
				$history->setActionPerson($ticket->getReferredPerson());
				$history->setNotes($_POST['notes']);
				$ticket->updateHistory($history);

				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		// Display the view
		$this->template->setFilename('tickets');
		if (isset($person)) {
			$this->template->blocks['ticket-panel'][] = new Block(
				'tickets/referTicketForm.inc',
				array('ticket'=>$ticket,'person'=>$person)
			);
		}
		else {
			$_REQUEST['return_url'] = BASE_URL.'/tickets/refer?ticket_id='.$ticket->getId();
			$this->template->blocks['ticket-panel'][] = new Block('people/searchForm.inc');
		}
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory(),'disableButtons'=>true)
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('ticket'=>$ticket,'issueList'=>$ticket->getIssues(),'disableButtons'=>true)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Other tickets for this location',
					'disableButtons'=>true
				)
			);
		}
	}

	/**
	 * @param REQUEST ticket_id
	 */
	public function recordAction()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		// Handle any stuff the user posts
		if (isset($_POST['action']) && $_POST['action']) {
			// add a record to ticket history
			$history = new History();
			$history->setAction($_POST['action']);
			$history->setActionDate($_POST['actionDate']);
			$history->setEnteredByPerson($_SESSION['USER']);
			$history->setActionPerson($_SESSION['USER']);
			$history->setNotes($_POST['notes']);
			$ticket->updateHistory($history);

			try {
				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		header('Location: '.$ticket->getURL());
		exit();
	}

	/**
	 * @param REQUEST ticket_id
	 */
	public function changeStatus()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		if (isset($_POST['status'])) {
			if ($_POST['status'] == 'closed') {
				header('Location: '.BASE_URL."/tickets/close?ticket_id={$ticket->getId()}");
				exit();
			}
			$ticket->setStatus($_POST['status']);

			// add a record to ticket history
			$history = new History();
			$history->setAction($_POST['status']);
			$history->setEnteredByPerson($_SESSION['USER']);
			$history->setActionPerson($_SESSION['USER']);
			$history->setNotes($_POST['notes']);
			$ticket->updateHistory($history);

			try {
				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		// Display the view
		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/changeStatusForm.inc',
			array('ticket'=>$ticket)
		);
		if ($ticket->getStatus() != 'closed') {
			$this->template->blocks['ticket-panel'][] = new Block(
				'tickets/closeTicketForm.inc',
				array('ticket'=>$ticket)
			);
		}
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory(),'disableButtons'=>true)
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('ticket'=>$ticket,'issueList'=>$ticket->getIssues(),'disableButtons'=>true)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Other tickets for this location',
					'disableButtons'=>true,
					'filterTicket'=>$ticket
				)
			);
		}
	}

	/**
	 * @param REQUEST ticket_id
	 * @param REQUEST location
	 */
	public function changeLocation()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		// Once the user has chosen a location, they'll pass it in here
		if (isset($_REQUEST['location']) && $_REQUEST['location']) {
			$ticket->clearAddressServiceData();
			$ticket->setLocation($_REQUEST['location']);
			$ticket->setAddressServiceData(AddressService::getLocationData($ticket->getLocation()));
			try {
				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		$_REQUEST['return_url'] = new URL($_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'locations/findLocationForm.inc',
			array('includeExternalResults'=>true)
		);
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory())
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('issueList'=>$ticket->getIssues(),'ticket'=>$ticket)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$ticketList = new TicketList(array('location'=>$ticket->getLocation()));
			if (count($ticketList) > 1) {
				$this->template->blocks['location-panel'][] = new Block(
					'tickets/ticketList.inc',
					array(
						'ticketList'=>$ticketList,
						'title'=>'Other tickets for this location',
						'filterTicket'=>$ticket
					)
				);
			}
		}
	}

	/**
	 * @param REQUEST ticket_id
	 * @param POST category_id
	 */
	public function changeCategory()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		if (isset($_POST['category_id'])) {
			try {
				$ticket->setCategory($_POST['category_id']);
				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		// Display the view
		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/changeCategoryForm.inc',
			array('ticket'=>$ticket)
		);
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory(),'disableButton'=>true)
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('ticket'=>$ticket,'issueList'=>$ticket->getIssues(),'disableButtons'=>true)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Other tickets for this location',
					'disableButtons'=>true,
					'filterTicket'=>$ticket
				)
			);
		}
	}

	/**
	 * @param REQUEST ticket_id
	 * @param POST resolution
	 */
	public function close()
	{
		$ticket = $this->loadTicket($_REQUEST['ticket_id']);

		if (isset($_POST['resolution'])) {
			$ticket->setResolution($_POST['resolution']);
			$ticket->setStatus('closed');

			// add a record to ticket history
			$history = new History();
			$history->setAction('close');
			$history->setEnteredByPerson($_SESSION['USER']);
			$history->setActionPerson($_SESSION['USER']);
			$history->setNotes($_POST['notes']);
			$ticket->updateHistory($history);

			try {
				$ticket->save();
				header('Location: '.$ticket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}


		// Display the view
		$this->template->setFilename('tickets');
		$this->template->blocks['ticket-panel'][] = new Block(
			'tickets/closeTicketForm.inc',
			array('ticket'=>$ticket)
		);
		$this->template->blocks['history-panel'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticket->getHistory(),'disableButtons'=>true)
		);
		$this->template->blocks['issue-panel'][] = new Block(
			'tickets/issueList.inc',
			array('ticket'=>$ticket,'issueList'=>$ticket->getIssues(),'disableButtons'=>true)
		);
		if ($ticket->getLocation()) {
			$this->template->blocks['location-panel'][] = new Block(
				'locations/locationInfo.inc',
				array('location'=>$ticket->getLocation())
			);
			$this->template->blocks['location-panel'][] = new Block(
				'tickets/ticketList.inc',
				array(
					'ticketList'=>new TicketList(array('location'=>$ticket->getLocation())),
					'title'=>'Other tickets for this location',
					'disableButtons'=>true,
					'filterTicket'=>$ticket
				)
			);
		}
	}

	/**
	 * Copies all data from one ticket to another, then deletes the empty ticket
	 *
	 * @param GET ticket_id_a
	 * @param GET ticket_id_b
	 */
	public function merge()
	{
		// Load the two tickets
		$ticketA = $this->loadTicket($_REQUEST['ticket_id_a']);
		$ticketB = $this->loadTicket($_REQUEST['ticket_id_b']);

		// When the user chooses a target, merge the other ticket into the target
		if (isset($_POST['targetTicket'])) {
			try {
				if ($_POST['targetTicket']=='a') {
					$ticketA->mergeFrom($ticketB);
					$targetTicket = $ticketA;
				}
				else {
					$ticketB->mergeFrom($ticketA);
					$targetTicket = $ticketB;
				}

				header('Location: '.$targetTicket->getURL());
				exit();
			}
			catch (Exception $e) {
				$_SESSION['errorMessages'][] = $e;
			}
		}

		// Display the form
		$this->template->setFilename('merging');
		$this->template->blocks[] = new Block(
			'tickets/mergeForm.inc',
			array('ticketA'=>$ticketA,'ticketB'=>$ticketB)
		);

		$this->template->blocks['merge-panel-one'][] = new Block(
			'tickets/ticketInfo.inc',
			array('ticket'=>$ticketA,'disableButtons'=>true)
		);
		$this->template->blocks['merge-panel-one'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticketA->getHistory(),'disableComments'=>true)
		);
		$this->template->blocks['merge-panel-one'][] = new Block(
			'tickets/issueList.inc',
			array(
				'issueList'=>$ticketA->getIssues(),
				'ticket'=>$ticketA,
				'disableButtons'=>true,
				'disableComments'=>true
			)
		);

		$this->template->blocks['merge-panel-two'][] = new Block(
			'tickets/ticketInfo.inc',
			array('ticket'=>$ticketB,'disableButtons'=>true)
		);
		$this->template->blocks['merge-panel-two'][] = new Block(
			'tickets/history.inc',
			array('history'=>$ticketB->getHistory(),'disableComments'=>true)
		);
		$this->template->blocks['merge-panel-two'][] = new Block(
			'tickets/issueList.inc',
			array(
				'issueList'=>$ticketB->getIssues(),
				'ticket'=>$ticketB,
				'disableButtons'=>true,
				'disableComments'=>true
			)
		);
	}
}
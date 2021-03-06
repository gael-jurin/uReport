<?php
/**
 * @copyright 2011-2016 City of Bloomington, Indiana
 * @license http://www.gnu.org/licenses/agpl.txt GNU/AGPL, see LICENSE.txt
 * @param string $this->location
 * @param bool   $this->disableButtons  (optional)
 * @param Ticket $this->ticket          (optional)
 */
use Application\Models\Person;
use Application\Models\TicketTable;

use Blossom\Classes\Block;
use Blossom\Classes\Url;

if ($this->location) {
    // This will properly encode the location string as a url parameter
	$url = new Url(BASE_URL.'/locations/view?location='.$this->location);

	$location = self::escape($this->location);

	$addTicketButton = '';
	if (!$this->disableButtons && Person::isAllowed('tickets', 'add')) {
		$h = $this->template->getHelper('buttonLink');
		$addTicketButton = $h->buttonLink(
			BASE_URI.'/tickets/add?location='.$this->location,
			$this->_('add_ticket'),
			'add'
		);
	}

	echo "
	<section id=\"locationInfo\">
        <header>
            <h1><a href=\"$url\" class=\"location\">$location</a></h1>
            <div class=\"tools\">$addTicketButton</div>
        </header>
    ";
        $table   = new TicketTable();
        $list    = $table->find(['location'=>$this->location]);
        $tickets = [];
        foreach ($list as $t) { $tickets[] = $t; }

        if ($this->ticket || count($tickets)) {
            $block = new Block('locations/geodata.inc', [
                'location'=> $this->location,
                'ticket'  => $this->ticket ? $this->ticket : $tickets[0]
            ]);
            echo $block->render('html', $this->template);
        }

        $this->_include('locations/locationPeople.inc');

        $t = $this->ticket ? 1 : 0;

        if (count($tickets) > 0) {
            $block = new Block('tickets/ticketList.inc', [
                'ticketList'    => $tickets,
                'title'         => 'Other cases for this location',
                'disableButtons'=> false,
                'fields'        => ['status', 'enteredDate', 'category']
            ]);
            if ($this->ticket) { $block->filterTicket = $this->ticket; }
            echo $block->render('html', $this->template);
        }
    echo "
	</section>
	";
}

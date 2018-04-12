<?php
// Initialize the model.
$gildedRose = new Inn();
// hard-code some rooms, a gnome
$gildedRose->addRoom(2, 0);
$gildedRose->addRoom(2, 1);
$gildedRose->addRoom(1, 0);
$gildedRose->addRoom(1, 2);
$gildedRose->addGnome("Steve");

// Handle requests
$request_method = $_SERVER['REQUEST_METHOD'];
$path_components = explode('/', trim($_SERVER['PATH_INFO'],'/'));
parse_str($_SERVER["QUERY_STRING"]);
// in the interest of programming time, no inputs are being sanitized
switch ($request_method) {
	case 'GET':
		switch ($path_components[0]) {
			case 'getRooms':
				$response = $gildedRose->getAvailableRooms(intval($start), intval($end));
				header('HTTP/1.1 200');
				header('Content-type: application/json');
				echo $response;
				break;
			case 'getSchedule':
				// todo: everything
				header('HTTP/1.1 200');
				header('Content-type: application/json');
				echo "{}";
				//gnomeManager.getSchedule($date);
				break;
			default:
				header('HTTP/1.1 404');
				break;
		}
		break;
	case 'POST':
		switch ($path_components[0]) {
			case 'bookRoom':
				$requestBody = json_decode(file_get_contents('php://input'), true);
				$response = $gildedRose->bookAvailableRoom(
					intval($requestBody['start']),
					intval($requestBody['end']),
					intval($requestBody['beds']),
					intval($requestBody['storage'])
				);
				header('HTTP/1.1 200');
				echo $response;
				break;
			default:
				header('HTTP/1.1 404');
				break;
		}
		break;
	default:
		header('HTTP/1.1 404');
		break;
}

// this class exists to encapsulate a RoomManager and GnomeManager instance,
// and provide a (potentially) friendlier interface to their functionalities
class Inn {
	private $roomManager;
	private $gnomeManager;

	public function __construct() {
		$this->roomManager = new RoomManager();
		$this->gnomeManager = new GnomeManager();
	}

	public function addRoom($nBeds, $nItems) {
		$this->roomManager->addRoom($nBeds, $nItems);
	}

	public function addGnome($name) {
		$this->gnomeManager->addGnome($name);
	}

	// Return a json representation of vacant rooms for a duration of time.
	// This will include partially vacant rooms, and those rooms will be flagged as such.
	public function getAvailableRooms($startDate, $endDate, $nBeds = 0, $nStorage = 0) {
		return $this->roomManager->getAvailableRooms($startDate, $endDate, $nBeds, $nStorage);
	}

	// Find a room to book and book it.
	// Returns room number on success, message on failure.
	public function bookAvailableRoom($startDate, $endDate, $nBeds, $nStorage) {
		return $this->roomManager->bookAvailableRoom($startDate, $endDate, $nBeds, $nStorage);
	}

}

// Some classes for our model. Business logic for checking and booking rooms would live here.
class RoomManager {
	private $rooms;
	private static $roomNumber = 1;

	public function __construct() {
		$this->rooms = Array();
	}

	public function addRoom($nBeds, $nStorage) {
		array_push($this->rooms, new Room(self::$roomNumber++, $nBeds, $nStorage));
	}

	// Return a json representation of vacant rooms for a duration of time.
	// This will include partially vacant rooms
	public function getAvailableRooms($startDate, $endDate, $nBeds, $nStorage) {
		$roomsAsJson = "[";
		foreach ($this->rooms as $room) {
			if ($room->isAvailable($startDate, $endDate, $nBeds, $nStorage)) {
				$roomsAsJson .= $room->toJson() . ",";
			}
		}
		// this is not great
		$roomsAsJson[-1] = "]";
		if (strlen($roomsAsJson) == 1) return false; // or []?
		return $roomsAsJson;
	}

	// Return information about the rooms
	public function bookAvailableRoom($startDate, $endDate, $nBeds, $nStorage) {
		$rooms = $this->getAvailableRooms($startDate, $endDate, $nBeds, $nStorage);

		// if there are no rooms that fit the request, there's nothing to book
		if (!$rooms) return "No available rooms fit those requirements.";

		// This is where we would use an algorithm to determine which room fits best.
		// I'm just going to pick the first one.
		$roomNumberToBook = intval(json_decode($rooms)[0]->roomNumber);

		// Write to a room's calendars (bed and storage),
		// maybe book() or bookResources() would be a better function name.
		// This would be better if it returned some kind of failure if it wasn't
		// capable of booking a room.
		$this->rooms[$roomNumberToBook-1]->bookRoom($startDate, $endDate, $nBeds, $nStorage);
		return strval($roomNumberToBook);
	}
}

// Represents a room, its number of beds, its amount of storage,
// and when such things are booked.
class Room {
	const base_room_cost = 10;
	const base_storage_cost = 2;
	private $roomNumber;
	private $nBeds;
	private $nStorage;

	// Room-sharing makes tracking resource (bed/storage) usage awkward.
	// ... I'm starting to dislike some of the architectural decisions I've made.
	private $bedCalendars;
	private $storageCalendars;

	public function __construct($roomNumber, $beds, $storage) {
		$this->roomNumber = $roomNumber;
		$this->nBeds = $beds;
		$this->nStorage = $storage;
		$this->bedCalendars = Array();
		$this->storageCalendars = Array();
		for ($i = 0; $i < $this->nBeds; $i++) {
			array_push($this->bedCalendars, new Calendar());
		}
		for ($i = 0; $i < $this->nStorage; $i++) {
			array_push($this->storageCalendars, new Calendar());
		}
	}

	// Time is encoded as an array of 48 bools, each of which represents a
	// half hour, i.e. 24 is the half hour block from noon to 12:30pm
	public function isAvailable($startDate, $endDate, $bedsNeeded, $storageNeeded) {
		// quit early if the room doesn't fit the bed/storage requirements
		if ($bedsNeeded > $this->nBeds || $storageNeeded > $this->nStorage ) return false;

		// for each day
		// iterate over each half-hour block, this is not performant
		// todo: add bounds checking, sanitize input well before this, etc
		for ($day = $startDate; $day < $endDate; $day++) {
			// For each half hour period, count the amount of beds/storage available.
			// Exit as soon as the room is unsuitable for the request.
			for ($halfHour = 0; $halfHour < 48; $halfHour++) {

				// count the number of occupied beds at this time
				$bedsAvailable = $this->nBeds;
				foreach ($this->bedCalendars as $bedCalendar) {
					if ($bedCalendar->getDay($day)[$halfHour]) {
						$bedsAvailable--;
					}
				}
				if ($bedsAvailable < $bedsNeeded) return false;
				// count the amount of occupied storage at this time
				$storageAvailable = $this->nStorage;
				foreach ($this->storageCalendars as $storageCalendar) {
					if ($storageCalendar->getDay($day)[$halfHour]) {
						$storageAvailable--;
					}
				}
				if ($storageAvailable < $storageNeeded) return false;
			}
		}
		return true;
	}

	// Flag a room's bed and storage calendars for a given time, to represent their allocation.
	public function bookRoom($startDate, $endDate, $nBedsRequired, $nStorageRequired) {
		for ($day = $startDate; $day < $endDate; $day++) {
			for ($halfHour = 0; $halfHour < 48; $halfHour++) {

				// Find "$nBedsRequired" empty beds
				// Provided that this function is called right after getAvailableRooms(),
				// and that there are no other race conditions, we should have a guarantee
				// that the resources (beds, storage) we're looking to allocate are available.
				// This is not a great solution because it's not performant, and the function
				// isn't itself checking to see if room conditions have changed.
				$nBedsAllocatedPerHalfHour = 0;
				foreach ($this->bedCalendars as &$bedCalendar) {
					if (!$bedCalendar->getDay($day)[$halfHour]) {
						// flag bed-halfhour as allocated
						$bedCalendar->allocateTime($day, $halfHour);
						// count towards # of beds being allocated
						$nBedsAllocatedPerHalfHour++;
					}
					// bail out of bed calendars when we've allocated enough beds
					if ($nBedsAllocatedPerHalfHour = $nBedsRequired) break;
				}

				// Same deal with allocating beds. This isn't DRY and could go into an
				// allocateResources() function or something similar.
				$nStorageAllocatedPerHalfHour = 0;
				foreach ($this->storageCalendars as &$storageCalendar) {
					if (!$storageCalendar->getDay($day)[$halfHour]) {
						$storageCalendar->allocateTime($day, $halfHour);
						$nStorageAllocatedPerHalfHour++;
					}
					if ($nStorageAllocatedPerHalfHour = $nStorageRequired) break;
				}
			}
		}
	}


	// returns json string representation of the room
	public function toJson() {
		return
			"{\"roomNumber\":\"{$this->roomNumber}\","
			. "\"beds\":\"{$this->nBeds}\","
			. "\"storage\":\"{$this->nStorage}\"}";
	}
}

class GnomeManager {
	private $gnomes;

	public function __construct() {
		$this->gnomes = Array();
	}

	public function addGnome($name) {
		array_push($this->gnomes, new Gnome($name));
	}

	// Give the first available gnome work.
	// This could be handled in many different ways, depending on how work should be
	// distributed among gnomes.
	// Availability is defined as not having work during the time blocks requested,
	// and not having a contiguous block of >8 hours of work after this task would be assigned.
	// This would return the time that the work is scheduled to be done, so that the room's
	// calendar might be adjusted i.e. if it'll be "occupied" for longer.
	public function allocateGnomeWork($duration, $earliestStartTime, $roomNumber) {}
}

class Gnome {
	private $name;
	private $schedule;

	public function __construct($name) {
		$this->name = $name;
		$this->schedule = new Calendar();
	}
}

// calendars consist of a single, 30-day month
// each day is broken into 48 half-hour increment.

// in lieu of a more fleshed out calendar, maybe it would have made sense
// to create two different calendar types; one for gnome schedules and one
// for room availability by day
class Calendar {
	private $days = Array();

	public function __construct() {
		for ($i = 0; $i < 30; $i++) {
			$this->days[$i] = Array();
			for ($j = 0; $j < 48; $j++) {
				$this->days[$i][$j] = false;
			}
		}
	}

	public function getDay($d) {
		return $this->days[$d];
	}

	public function allocateTime($d, $t) {
		$this->days[$d][$t] = true;
	}
}

?>


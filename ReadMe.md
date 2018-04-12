# To run

Clone the project and navigate to its directory.

Run `php -S localhost:8000` in a terminal window.

# To make a request

- n.b. Dates are represented as integers between `0` and `29`.

To get a manifest of rooms available between two dates, e.g. 3 and 21:
`curl -i "localhost:8000/api.php/getRooms?start=3&end=21"`

To book a room between two dates, with storage and bed requirements:
`curl -i -d '{"start":"3", "end":"14", "beds":"1", "storage":"1"}' -H "Content-Type: application/json" -X POST "localhost:8000/api.php/bookRoom"`
* This doesn't work as I'd planned. The data changed doesn't seem to be persisting
between requests. I believe this is either from me misunderstanding how objects
persist in memory in the PHP's built-in server, or because I'm writing to a copy
of my data model rather than a reference to one. I'm still refamiliarizing myself
with how PHP handles these things.

To get a Gnome's schedule for a day (not implemented):
`curl -i "localhost:8000/api.php/getSchedule?date=3"`

# Architecture

The Inn class encapsulates our data model. The Inn has a RoomManager and a
GnomeManager.

The RoomManager has Rooms, and it acts as an interface to their
functionality. The RoomManager decides who to book/allocate Rooms based on
what resources (storage or beds) are required. It informs the Rooms themselves
to update their resource Calendars when their resources are booked. Each Room
has, for each of its storages and beds, a Calendar. A Calendar is which is a
collection of 30 * 48 bools. It represents a single 30-day month, with each day
broken into 48 half-hour blocks. A block is `true` if the resource the Calendar
represents is in use for the given half-hour block; otherwise it is `false`.

The goal of the partially-implemented GnomeManager was to work similarly to the
RoomManager. It has a collection of Gnomes, those Gnomes have Calendars which
represent their schedules. The GnomeManager gives work to Gnomes, such that
their schedules follow certain rules e.g. not working more than 8 contiguous
hours.

The Inn class would additionally allow information to come back from the
GnomeManager, ie additional time that a Room must be considered occupied
for the purpose of cleaning, such that that Room's calendars can then be
adjusted.

# Resources

This project used StackOverflow regularly for help PHP syntax and semantics.
The following webpage was used to see how to handle requests and send responses:
https://web.archive.org/web/20130910164802/http://www.gen-x-design.com/archives/create-a-rest-api-with-php/

# Libraries

No third-party libraries were used, though I did look for a back-end Calendar
library to avoid writing my own, simplified one.

# Time

I spent at least 8 hours on this project. If I had unlimited time with this
project, I would use a real, persistent calendar back-end, perhaps Google Calendar
to start with if I didn't want to write my own. I think this would fix the issue
with calendar writes not persisting. I feel like it goes without saying, but were
this production code, it'd have: any sort of bounds checking, input sanitization,
classes like Calendar, Room, Gnome, etc would all have unit tests running via
automation, classes would be broken out into different files, comments would be more
formal, there would be javadoc-style comments describing what files and functions are
for, along with their arguments and return values, there there would be greater
stylistic consistency via linting (e.g. single vs. double quoted strings), etc.
There would be classes devoted to consuming input and creating output.
I would also have run my architecture by at least a couple of people before writing
code at all.
... and the structure of the project in git would be rebased and reorganized into
commits based on functionality added, rather than work done.

# Automated testing

Travis is a tool I've explored in the past to handle automated testing. My first stop
would be to write tests around the classes I've written, which are (I think) already
relatively atomic with regards to functionality. It would be reasonable to write
tests that hit the endpoints I've written with good and bad data, and to test edge cases.
It could also be useful to hammer the endpoints with traffic to see what fails first.

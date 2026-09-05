<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
enum BookingStatus:string{case Booked='Booked';case Amended='Amended';case Cancelled='Cancelled';case Travelled='Travelled';case Returned='Returned';}

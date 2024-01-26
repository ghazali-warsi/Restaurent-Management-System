<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Rules\DateBetween;
use App\Rules\TimeBetween;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Requests\ReservationStoreRequest;
use App\Enums\TableStatus;
use App\Models\Table;
use App\Http\Controllers\Frontend\Session;
use App\Mail\ReservationConfirmation;
use Illuminate\Support\Facades\Mail;
use App\Jobs\SendReservationReminder;
use Illuminate\Database\Eloquent\ModelNotFoundException; // Don't forget to include this
use Illuminate\Support\Facades\Log;
use App\Jobs\TableAvailabilityJob;
use PDF; // Import the PDF facade

class reservationController extends Controller
{
    //
  

    
    public function stepone(Request $request)
{
    $reservation = $request->session()->get('reservation');
    $min_date = Carbon::today();

    // Set the maximum date to 2 days from today (including today)
    $max_date = Carbon::today()->addDays(2);

    // Get the current time
    $current_time = Carbon::now();

    // Set the minimum time (5 PM)
    $min_time = Carbon::createFromTime(5, 0, 0);

    // Set the maximum time (12:00 AM of the next day)
    $max_time = Carbon::tomorrow(); // This sets it to midnight (12:00 AM)

    // Check if the current time is within the allowed time range
    $within_time_range = $current_time->between($min_time, $max_time);

    $guestNumbers = Table::pluck('guest_number'); // Fetch guest numbers from the "table" table

    // If there's an active reservation in the session, check if the selected table is already reserved
    if ($reservation) {
        $reservationDate = Carbon::parse($reservation->reservation_date);
        $reservationEnd = Carbon::parse($reservation->reservation_end);

        // Calculate the end time of the current reservation plus two hours
        $reservationEndPlusTwoHours = $reservationEnd->copy()->addHours(2);

        // Get all tables that are currently reserved within the reservation time slot
        $reservedTables = Reservation::where(function ($query) use ($reservationDate, $reservationEndPlusTwoHours) {
            $query->where('reservation_date', '>=', $reservationDate)
                ->where('reservation_end', '<=', $reservationEndPlusTwoHours);
        })->pluck('table_id')->toArray();

        // Filter out tables that are already reserved and are not available
        $guestNumbers = $guestNumbers->reject(function ($guestNumber) use ($reservedTables) {
            return in_array($guestNumber, $reservedTables);
        });
    }

    return view('Frontend.Reservation.step-one', compact('reservation', 'min_date', 'max_date', 'within_time_range','guestNumbers'));}


    public function storestepone(Request $request)
    {
        // Validate the reservation form data
        $validated = $request->validate([
            'firstname' => ['required'],
            'lastname' => ['required'],
            'email' => ['required'],
            'tel_number' => ['required'],
            'reservation_date' => ['required', 'date'],
            'guest_number' => ['required', 'integer', 'min:1'],
        ]);
    
// Calculate reservation start and end times
$reservationDate = Carbon::parse($validated['reservation_date']);
$reservationEnd = $reservationDate->copy()->addHours(2); // 2 hours duration

// Check if there are any overlapping reservations for the selected table and time slot
$existingReservations = Reservation::where('guest_number', $validated['guest_number'])
    ->where(function ($query) use ($reservationDate, $reservationEnd) {
        // Check for reservations that overlap with the specified time slot
        $query->where(function ($query) use ($reservationDate, $reservationEnd) {
            $query->where('reservation_date', '<=', $reservationDate)
                ->where('reservation_end', '>=', $reservationDate)
                ->where('status', '<>', 'canceled'); // Exclude canceled reservations
        })->orWhere(function ($query) use ($reservationDate, $reservationEnd) {
            $query->where('reservation_date', '<=', $reservationEnd)
                ->where('reservation_end', '>=', $reservationEnd)
                ->where('status', '<>', 'canceled'); // Exclude canceled reservations
        });
    })
    ->count();

if ($existingReservations > 0) {
    // Reservations already exist with the selected guest number within the time slot
    $request->session()->flash('guest_number_error', 'Table with this guest number is already reserved for the selected time slot.');
    return redirect()->back()->withInput();
}

   // Create a new instance of the Reservation model
   $reservation = new Reservation();

   // Fill the model attributes with the validated data
   $reservation->fill($validated);

   // Set the reservation duration to 2 hours
   $reservation->duration_hours = 2;

   // Set the reservation start and end times
   $reservation->reservation_date = $reservationDate;
   $reservation->reservation_end = $reservationEnd;

   // Save the reservation data to the session
   $request->session()->put('reservation', $reservation);       
    return redirect('/reservation/step-two');
    }


    public function steptwo(Request $request)
    {
        //
         // Retrieve the authenticated user's ID
        $user_id = auth()->user()->id;

        $reservation = $request->session()->get('reservation');
    $reservationEnd = Carbon::parse($reservation->reservation_end);
        
    

    $res_table_ids = Reservation::orderBy('reservation_date')
    ->get()
    ->filter(function ($value) use ($reservationEnd) {
        $valueEndDate = Carbon::parse($value->reservation_end);

        // Check if the table is reserved beyond the current reservation end time
        return $valueEndDate->gt($reservationEnd);
    })

        ->pluck('table_id');
    
        $table = Table::where('status', '=', TableStatus::Avaliable)
        ->where('guest_number', '>=', $reservation->guest_number)
        ->whereNotIn('id', $res_table_ids)
        ->get();
         
        return view('Frontend.Reservation.step-two',compact('reservation','table'));

    }


    public function storesteptwo(Request $request)
    {
        $validated = $request->validate([
            'table_id' => ['required']
        ]);
    
        // Retrieve the reservation data from the session
        $reservation = $request->session()->get('reservation');
        
         // Assign the authenticated user's ID to the reservation
          $reservation->user_id = auth()->user()->id;
    
        // Update the reservation with validated data
        $reservation->fill($validated);
        $reservation->save();


    
        try {
            // Fetch reservation from the database using reservation ID
            $reservationInstance = Reservation::findOrFail($reservation->id);
    
            // Calculate the reminder time (1 hour before reservation)
            $remindTime = Carbon::parse($reservationInstance->reservation_date)->subHour(1);
    
            Log::debug('Attempting to dispatch reminder email job...');
            // Dispatch the job to send the reminder email with a delay
            SendReservationReminder::dispatch($reservationInstance)->delay($remindTime);
            Log::debug('Reminder email job dispatched successfully.');
        
            Log::info('Reminder email job dispatched for reservation: ' . $reservationInstance->id);
        } 
        catch (ModelNotFoundException $e) {
            Log::error('Error fetching reservation: ' . $e->getMessage());
            // Handle the exception, e.g., log an error or show a user-friendly message
        }
    
        // Send the confirmation email
        Mail::to($reservation->email)->send(new ReservationConfirmation($reservation));
    
        // Clear the session reservation data
        $request->session()->forget('reservation');
    
        Log::info('Reservation confirmation email sent for reservation: ' . $reservation->id);
    
        return redirect('/thankYou')->with('reservation', $reservation);
    }
    
    
    public function thankYou()
{
    // Retrieve the reservation data from the session
    $reservation = session('reservation');

    // Return the "Thank You" view and pass the reservation data
    return view('Frontend.Reservation.thank-you', compact('reservation'));
}

    
        
    public function index()
    {
        $user = auth()->user();

        // Use the relationship to retrieve reservations associated with the user
        $reservations = $user->reservations()->latest()->paginate(10);
    
        return view('Frontend.Reservation.index', compact('reservations'));
    }


    public function show($id)
    {
        
        // Retrieve the reservation by its ID from the database
        $reservation = Reservation::findOrFail($id);
    
        // Check if the reservation exists
        if (!$reservation) {
            // Handle the case when the reservation does not exist (e.g., show an error message or redirect)
            // For example:
            return redirect('/reservation/step-one')->with('error', 'Reservation not found.');
        }
    
        // Calculate the cancellation time (one day after creation)
        $cancellationTime = strtotime($reservation->created_at) + (24 * 60 * 60);
    
        // Pass the calculated cancellation time to the view
        return view('Frontend.Reservation.show', compact('reservation', 'cancellationTime'));
    }

public function cancel(Request $request, $id)
{
  // Find the reservation by ID
  $reservation = Reservation::findOrFail($id);

  // Calculate the time one day after the reservation was created
  $oneDayAfterCreation = strtotime($reservation->created_at) + (24 * 60 * 60);

  // Check if the current time is after the one-day window from creation
  if (time() > $oneDayAfterCreation) {
      return redirect()->back()->with('error', 'Cannot cancel the reservation as one day has passed since its creation.');
  }

  // Check if the reservation is already canceled
  if ($reservation->status === 'canceled') {
      return redirect()->back()->with('error', 'Reservation is already canceled.');
  }

  // Update the reservation status to 'canceled'
  $reservation->status = 'canceled';
  $reservation->save();

  // Release the associated table (make it available)
  $table = Table::find($reservation->table_id);
  if ($table) {
      $table->status = 'avaliable';
      $table->save();
  }

  // Add any additional cancellation logic here, e.g., notifying the user

  return redirect()->back()->with('success', 'Reservation canceled successfully.');
}

public function generatePdf($id)
{
    // Fetch reservation details using $id (you might use Eloquent)
    $reservation = Reservation::findOrFail($id);

    // Generate the PDF using the dompdf library
    $pdf = PDF::loadView('pdf.reservation_details', compact('reservation'));

    // Return the PDF as a downloadable response
    return $pdf->download('reservation_details.pdf');
}

}

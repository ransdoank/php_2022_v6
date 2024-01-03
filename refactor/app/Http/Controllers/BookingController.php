<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */

class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    // protected $repository;

    // -------------------------------------------
    // What Makes It OK:
    //     - Dependency Injection: The practice of injecting dependencies is being followed, which is great for decoupling, testability, and adhering to the - SOLID principles.
    //     - Clean Constructor: The constructor is simple and its purpose is clear – to inject the BookingRepository.

    // Improve:
    //     - Property Type Declaration: With PHP 7.4 and later versions, you can declare types for class properties, which helps with ensuring the correct type is always used and can make the code more self-documenting.
    //     - Visibility Best Practices: Depending on how the $repository property is used within subclasses or if you plan to allow modifications from child classes, you might want to consider making it private instead of protected to enforce encapsulation.
    //     - PHPDoc Comments: The current comment block is quite minimal. Expanding the PHPDoc comments can be helpful, especially to explain the purpose of the BookingRepository and any wider context that it might be useful for other developers to know.
    //     - Use of Laravel Features: In Laravel, you can accomplish much of what this controller is doing simply by typing-hinting in the method signatures instead of defining properties and injecting through the constructor, if that fits the usage pattern.

    // Here is how you could potentially improve it:
    /**
     * The repository for handling bookings operations.
     *
     * @var BookingRepository
     */
    private BookingRepository $repository;


    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */

    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        // if($user_id = $request->get('user_id')) {

        //     $response = $this->repository->getUsersJobs($user_id);

        // }
        // elseif($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID'))
        // {
        //     $response = $this->repository->getAll($request);
        // }

        // return response($response);
        // -------------------------------------------
        // Issues:
        //     - Direct Environment Variable Access: Accessing the env function directly in the code outside of configuration files is not recommended as it only works when the configuration is not cached. It's better to use configuration files with Laravel.
        //     - Implicit Authentication Check: The code accesses a property __authenticatedUser on the $request, which is not a standard Laravel property and implies that authentication logic might be scattered throughout the application.
        //     - Error Handling: The method lacks error handling. If no user_id is provided and the authenticated user is not an admin, it's not clear what $response would contain.
        //     - Clarification of Business Logic: The conditionals slightly obfuscate the business logic, making it less clear at a glance what the method is supposed to do.
        //     - Response Standardization: It provides no standardized way to handle cases where no data is found or no user ID is given, and there's no admin to return all jobs.

        // Improvements:
        //     - Configuration Usage: Use Laravel's configuration system to reference roles. Set these in a config file that references environment variables.
        //     - Middleware Integration: Utilize middleware for authentication to clarify the flow and offload the responsibility from the controller.
        //     - Exception Handling: Properly handle cases of unauthorized access and missing parameters.
        //     - Type-Hinting: Leverage form requests for validation and authorization.

        // Here is a revised version of the method:
        if ($request->filled('user_id')) {
            $response = $this->repository->getUsersJobs($request->input('user_id'));
        } else {
            $user = $request->user(); // Use Laravel's built-in method to retrieve the authenticated user.
            
            if (in_array($user->user_type, config('auth.admin_roles'), true)) {
                $response = $this->repository->getAll($request);
            } else {
                return response()->json(['error' => 'Not authorized.'], 403);
            }
        }
    
        return response()->json($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        // $job = $this->repository->with('translatorJobRel.user')->find($id);

        // return response($job);

        // -------------------------------------------
        // Positive Aspects:
        //     - Simplicity: The method is straightforward, retrieving a job using its ID, then returning it. The simplicity allows for easy interpretation and debugging.
        //     - Utilizing Repository: Leveraging a repository to encapsulate data logic abstracts the controller from the data layer, making the codebase more maintainable.
        //     - Eager Loading: By using with('translatorJobRel.user'), the method performs eager loading to retrieve related models, which can help reduce the number of database queries and improve performance.

        // Improvement:
        //     - Error Handling: There's no apparent error handling if no job is found for the given $id. If find returns null, the method will still try to return a response, which may not be the intended behavior.
        //     - Response Standardization: It does not standardize the response format or the HTTP status code, which is important for API consistency, especially in error scenarios.
        //     - Method Documentation: The PHPDoc comment does not accurately describe the parameter $id or what "mixed" means for the return type. More specific documentation would be beneficial.
        //     - Type Safety: There is no type hinting for the $id parameter, which means that the method could be called with any type of variable.

        // Refactored Code with Improvements:
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        if (!$job) {
            // Assuming a job not found should return a 404 not found response.
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        // $data = $request->all();

        // $response = $this->repository->store($request->__authenticatedUser, $data);

        // return response($response);

        // -------------------------------------------
        // What's Generally OK:
        //     - Conciseness: The method is concise which generally simplifies understanding and maintenance.
        //     - Indentation and Style: It follows PHP's PSR (PHP Standards Recommendations) styling norms which promote readability.
        //     - Using a Repository: The usage of a repository is positive for abstracting business logic away from the controller.

        // Improvement:
        //     - Validation: The code lacks validation for the incoming request data. Without validation, there is a risk of persisting invalid or even harmful data.
        //     - Security: The method uses $request->all(), which blindly accepts all parameters passed in the request. This approach leaves the application vulnerable to mass-assignment attacks unless guarded appropriately in the model.
        //     - Authentication: Like in other code snippets you provided, it accesses a non-standard property __authenticatedUser on the request object. This isn't a conventional part of the Laravel request and might imply custom modifications or middleware that aren't clear within the context of the code, potentially impacting maintainability and clarity.
        //     - Error Handling: If the store operation fails for any reason, such as a database error, the method does not seem to handle this case.
        //     - Documentation: The doc comment indicates a return type of mixed, which lacks specificity. Detailed return type information could be beneficial. Additionally, there is no indication of what kind of response is returned on success or failure.
        //     - State Change Via GET Method: It's not explicitly clear, but if store is intended to be used with an HTTP GET method, that would be incorrect. This method should be reserved for POST requests since it changes the state of the application by adding a new resource.

        // Improved Code:
        $validatedData = $request->validate([
            // Define your validation rules here, for example:
            'title' => 'required|max:255',
            // Other fields...
        ]);
    
        try {
            $user = $request->user(); // Assuming middleware sets the authenticated user
            $response = $this->repository->store($user, $validatedData);
            
            return response()->json($response); // Assume $response is appropriate for the client
        } catch (\Exception $e) {
            // Log the error or handle it as appropriate
            return response()->json(['message' => 'Failed to store resource.'], 500);
        }
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        // $data = $request->all();
        // $cuser = $request->__authenticatedUser;
        // $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        // return response($response);

        // -------------------------------------------
        // Improvement:
        //     - Security: The method utilizes $request->all() to retrieve input data, which could expose the application to mass-assignment vulnerabilities. Even though the array_except function is used to remove the _token and submit fields, an allowlist approach (explicitly defining the fields that can be updated) is usually safer.
        //     - Authentication: The code accesses an unconventional property __authenticatedUser, which isn't part of the standard Laravel Request object. This indicates there might be custom middleware adding this property, but the method lacks clarity without it being explicit in the code.
        //     - Validation: The method lacks input validation, which is necessary to ensure the integrity of incoming data before it is processed.
        //     - Error Handling: There is an absence of error handling if, for example, the updateJob method fails or the job with the specified $id doesn't exist.
        //     - Return Type Documentation: The PHPDoc block only defines the return type as mixed, which is not very informative. It would be beneficial to specify what kinds of responses can be expected.
        //     - Deprecation: The array_except function was removed as of Laravel 6.x. If you are using a recent version of Laravel, this would result in an error.

        $validatedData = $request->validate([
            // Define your validation rules here
            'field1' => 'required|string',
            'field2' => 'nullable|integer',
            // ...
        ]);
    
        try {
            $user = $request->user();  // Utilize Laravel's built-in method to retrieve the authenticated user
            $response = $this->repository->updateJob($id, $validatedData, $user);
    
            return response()->json($response);  // Assume response is appropriate for the client
        } catch (\Exception $e) {
            // Log the error and handle it as appropriate
            // Consider what kind of error message if any, should be returned to the client
            return response()->json(['message' => 'Failed to update job.'], 500);
        }
        
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        $response = $this->repository->storeJobEmail($data);

        return response($response);
        // -------------------------------------------
        // What's OK:
        //     - Use of Configuration: The $adminSenderEmail is fetched from the application configuration, which is good practice for easy management of such settings.
        //     - Repository Utilization: The usage of a repository helps in abstracting the logic from the controller, keeping the code clean.

        // Improvement:
        //     - Unused Variable: The $adminSenderEmail variable is defined but not used in this method. This is dead code and should be removed if it's indeed not used.
        //     - Validation: The method lacks validation checks. Directly using $request->all() fetches all user input, which could lead to mass-assignment vulnerabilities or the inclusion of unexpected/bad data.
        //     - Error Handling: There are no error checks. If storeJobEmail fails, there's no indication of how the system would respond.
        //     - Response Handling: The method assumes a successful operation and returns $response directly, without considering the various types of HTTP responses that could be applicable based on the operation's result.
        //     - Documentation: The PHPDoc block could be more specific about what the method returns (the return type is merely listed as mixed).
        //     - Handling of Admin Email: It's unclear why the $adminSenderEmail is fetched since it's not used in the visible code, which may suggest incomplete implementation.

        // Here's improve the code:
        $validatedData = $request->validate([
            // Define the validation rules applicable for the email job
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            // other necessary fields...
        ]);
    
        try {
            $response = $this->repository->storeJobEmail($validatedData);
    
            // Assuming `$response` holds status and message of the operation
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to store email job.'], 500);
        }
        
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        // if($user_id = $request->get('user_id')) {
            
        //     $response = $this->repository->getUsersJobsHistory($user_id, $request);
        //     return response($response);
        // }
        
        // return null;
        // -------------------------------------------
        // What's OK:
        //     - Simplicity: The method's logic is straightforward—if a user_id is provided in the request, it will fetch the user's jobs history.
        //     - Condition Check: It performs a condition check to see if the user_id exists, which is a good practice to validate the presence of required parameters.

        // Improvement:
        //     - Unclear Return Type: The method's PHPDoc comment specifies a mixed return type, which does not give clarity into what the caller should expect—will it return an HTTP response or just data?
        //     - Assignment in Conditional: It's using an assignment within an if statement (if($user_id = $request->get('user_id'))), which can be a point of confusion and potential bugs, as it may be mistaken for an equality check.
        //     - Loose Validation: The code doesn't perform strict validation of the user_id parameter aside from checking its existence. This can result in unexpected behavior or more serious security issues if improper input is passed.
        //     - Inconsistent Return Types: The method returns a response object if a user's job history is found, but returns null if no user_id is provided. This inconsistency can lead to confusion for the client of this API.
        //     - Error Handling: There is no error handling for cases where the user_id is invalid or doesn't correspond to a user in the system.
        //     - Redundant Request Passing: The method passes the entire $request object to the repository method even though only user_id seems required, which could be unnecessary or risky if not handled properly within the repository method.

        $validatedData = $request->validate([
            'user_id' => 'required|integer|exists:users,id', // Assumes there's a 'users' table with an 'id' column
        ]);
    
        $response = $this->repository->getUsersJobsHistory($validatedData['user_id']);
    
        return response()->json($response);
    }
    
    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        
        $response = $this->repository->acceptJob($data, $user);
        
        return response($response);
        // -------------------------------------------
        // Positives:
        //     - Repository Use: Utilizing a repository is a good practice since it provides a level of abstraction for data handling and can simplify the process of testing the controller.

        // Improvement:
        //     - Definition of __authenticatedUser: The code uses $request->__authenticatedUser, which is non-standard for Laravel. A cleaner approach would use $request->user() to get the authenticated user, assuming appropriate middleware is in place.
        //     - Lack of Validation: The method uses $request->all() to retrieve all the request data without validation. This can be insecure as it may facilitate mass-assignment vulnerabilities, and the method does not verify the integrity of the input data.
        //     - Return Type Ambiguity: The PHPDoc comment specifies the return type as mixed, which is vague. It would be more clear if it specified what kind of response is being returned, aiding in self-documentation and providing insights for other developers.
        //     - Error Handling: There is no evidence of error handling within this code. If the job acceptance process fails (for example, if the job has already been accepted by someone else), the method doesn't account for how to handle such an error.

        $validatedData = $request->validate([
            // Validation rules for accepting a job
            'job_id' => 'required|integer|exists:jobs,id',
            // Add other necessary validation rules
        ]);
    
        // Assuming you have a proper authentication middleware in place
        $user = $request->user();
    
        try {
            $response = $this->repository->acceptJob($validatedData, $user);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            // Log the exception detail using a logger and return a user friendly error message
            return response()->json(['message' => 'Job acceptance failed.'], 500);
        }
    }
    
    public function acceptJobWithId(Request $request)
    {
        // $data = $request->get('job_id');
        // $user = $request->__authenticatedUser;

        // $response = $this->repository->acceptJobWithId($data, $user);

        // return response($response);
        // -------------------------------------------
        // Improvement:
        // - Authentication Handling: The method accesses a non-standard __authenticatedUser property on the $request. The standard Laravel convention is to use $request->user() to get the authenticated user object.
        // - Input Validation: The job ID is retrieved from the request but is not validated. There is no check to ensure it is the correct data type or if it exists in the database, which could lead to runtime errors or security vulnerabilities.
        // - Direct Data Access: Directly using $request->get('job_id') without validation is risky. Without ensuring the existence and correctness of 'job_id', the function may proceed with incorrect data.
        // - Error Handling: There is no apparent error handling in case the acceptJobWithId() repository method fails, such as when the specified job ID does not exist.
        // - Response Information: There isn't enough context to the nature of the $response variable. The code assumes success and doesn't handle different response states properly.
        // - API Response Clarity: Returning simply response($response); does not indicate the HTTP status code or content type, which is not a best practice for RESTful API design.
        $validatedData = $request->validate([
            'job_id' => 'required|integer|exists:jobs,id', // assumes 'jobs' is the table and 'id' is the field
        ]);
    
        // Get the authenticated user via the request
        $user = $request->user();
    
        try {
            $response = $this->repository->acceptJobWithId($validatedData['job_id'], $user);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            // Log the error and provide an appropriate error response
            return response()->json(['message' => 'Unable to accept job.'], 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        // $data = $request->all();
        // $user = $request->__authenticatedUser;
        
        // $response = $this->repository->cancelJobAjax($data, $user);
        
        // return response($response);
        // -------------------------------------------
        // Improvement:
        //     - Direct Property Access: The method accesses a non-standard property __authenticatedUser of the $request object. This is not typical in Laravel applications where the $request->user() function is commonly used to retrieve the authenticated user.
        //     - Lack of Input Validation: Using $request->all() directly is dangerous as it might expose the application to mass-assignment vulnerabilities, and it does not ensure the provided data is valid or sanitized.
        //     - No Error Handling: If the cancelJobAjax function throws an exception or fails to cancel the job for some reason, it's not handled, and the user could receive an unhelpful server error response.
        //     - Inconsistent API Response Structure: The API response format can be unclear, especially if the structure of $response is inconsistent. Furthermore, the method does not define response codes that can give clients more context about the result of the request.
        //     - PHPDoc Accuracy: The PHPDoc block's @return mixed annotation does not accurately inform about the type of response being returned, which should ideally be a specific type or a set of types.
        $validatedData = $request->validate([
            'job_id' => 'required|int|exists:jobs,id',
        ]);
        
        $user = $request->user(); // This assumes you have the auth middleware applied.
    
        try {
            $response = $this->repository->cancelJobAjax($validatedData['job_id'], $user);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            // Depending on the type of exceptions you expect, handle them appropriately.
            // For the sake of an example, a general catch all is provided.
            return response()->json(['message' => 'Failed to cancel job.'], 500);
        }

    }
    
    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        // $data = $request->all();

        // $response = $this->repository->endJob($data);

        // return response($response);
        // -------------------------------------------
        // Improvement:
        //     - Lack of Validation: The code snippet doesn't perform any validation on the incoming request data. This could lead to processing incorrect or malicious data, which might compromise data integrity or system security.
        //     - Security Risk (Mass Assignment): Utilizing $request->all() could inadvertently expose every incoming request parameter to the model update if not guarded against in the model. This is an anti-pattern called "mass assignment".
        //     - Inadequate Error Handling: The method does not include error handling, which would be necessary if the repository's endJob method throws an exception or fails to update the record.
        //     - Poor Documentation: The PHPDoc comment indicates a mixed return type, which is ambiguous. More specific comments are needed for clarity, including describing the data expected in $request.
        //     - Inconsistent Response Handling: The method provides no HTTP status code context or content type - it could return different types of response bodies, potentially making it harder for clients to handle the response correctly.
        //     - Clarity and Maintenance: The method doesn't check for the presence of necessary parameters (e.g., job ID) before proceeding with the operation, implying that it might not be robust against different cases of input.
        $validatedData = $request->validate([
            'job_id' => 'required|integer|exists:jobs,id',
            // Validate other necessary fields...
        ]);
    
        try {
            $response = $this->repository->endJob($validatedData);
            return response()->json($response, 200); // Assuming success returns a relevant response
        } catch (\Exception $e) {
            // Log exception with a logger or perform other necessary error handling
            return response()->json(['message' => 'Unable to end job.'], 500);
        }

    }
    
    public function customerNotCall(Request $request)
    {
        // $data = $request->all();

        // $response = $this->repository->customerNotCall($data);

        // return response($response);
        // -------------------------------------------
        // Improvement:
        // - Lack of Validation: The code performs no validation on the input data from $request->all(), potentially leading to mass-assignment vulnerabilities or processing of bad data.
        // - Error Handling: There is no error handling in case the repository method customerNotCall fails, which could lead to an unhandled exception and a generic error message for the end-user.
        // - Ambiguity in Response Structure: The method returns $response directly without specifying the response format or HTTP status codes, which may cause inconsistency, especially if the $response is not in a standardized format.
        // - PHPDoc Ambiguity: The PHPDoc comment indicates a return type of mixed, which lacks specificity. Better documentation could indicate what types of values it returns and under what conditions.
        // - Poor Method Naming: The method name customerNotCall is not intuitively clear. A more descriptive method name could offer better insights into the purpose and expectations of the method.
        $validatedData = $request->validate([
            'job_id' => 'required|integer|exists:jobs,id',
            // Other necessary fields and rules
        ]);
    
        // The method name is assumed to reflect an event where a customer didn't call, and this might require specific data, hence the validation rule examples.
    
        try {
            $response = $this->repository->recordCustomerNoCall($validatedData);
            return response()->json($response, 200); // Assuming the operation is successful and $response contains the data to be returned.
        } catch (\Exception $e) {
            // Depending on business logic, you might want to log the exception detail using a logger.
            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        // $data = $request->all();
        // $user = $request->__authenticatedUser;

        // $response = $this->repository->getPotentialJobs($user);

        // return response($response);
        // -------------------------------------------
        // Improvement:
        // - Authentication Property Access: The $request->__authenticatedUser property is non-standard in Laravel. Normally, the authenticated user is accessed via $request->user() which is the conventional and documented approach.
        // - Lack of Input Validation: The method starts by getting all request data with $request->all(), but this data isn't used within the code as shown. It retrieves the request data but then doesn't use it for retrieving potential jobs.
        // - Error Handling and Response: There is no error handling in the code, and the possible types of responses from the repository method are not documented or handled.
        // - Mixed Return Type Specification: The PHPDoc comment specifies the return type as mixed, which is not descriptive. PHPDoc should ideally provide more information about what is returned.
        // - Unused Variable: $data is defined but not used, making it redundant and potentially confusing.
        $user = $request->user(); // Correct way to retrieve the authenticated user

        try {
            $response = $this->repository->getPotentialJobs($user);
            return response()->json($response); // Assuming the repository returns an array that can be converted to JSON
        } catch (\Exception $e) {
            // Log the exception and maybe return a more informative response
            return response()->json(['message' => 'Could not retrieve potential jobs.'], 500);
        }
    }

    public function distanceFeed(Request $request)
    {
        // $data = $request->all();

        // if (isset($data['distance']) && $data['distance'] != "") {
        //     $distance = $data['distance'];
        // } else {
        //     $distance = "";
        // }
        // if (isset($data['time']) && $data['time'] != "") {
        //     $time = $data['time'];
        // } else {
        //     $time = "";
        // }
        // if (isset($data['jobid']) && $data['jobid'] != "") {
        //     $jobid = $data['jobid'];
        // }

        // if (isset($data['session_time']) && $data['session_time'] != "") {
        //     $session = $data['session_time'];
        // } else {
        //     $session = "";
        // }

        // if ($data['flagged'] == 'true') {
        //     if($data['admincomment'] == '') return "Please, add comment";
        //     $flagged = 'yes';
        // } else {
        //     $flagged = 'no';
        // }
        
        // if ($data['manually_handled'] == 'true') {
        //     $manually_handled = 'yes';
        // } else {
        //     $manually_handled = 'no';
        // }

        // if ($data['by_admin'] == 'true') {
        //     $by_admin = 'yes';
        // } else {
        //     $by_admin = 'no';
        // }

        // if (isset($data['admincomment']) && $data['admincomment'] != "") {
        //     $admincomment = $data['admincomment'];
        // } else {
        //     $admincomment = "";
        // }
        // if ($time || $distance) {

        //     $affectedRows = Distance::where('job_id', '=', $jobid)->update(array('distance' => $distance, 'time' => $time));
        // }

        // if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {

        //     $affectedRows1 = Job::where('id', '=', $jobid)->update(array('admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin));

        // }

        // return response('Record updated!');
        // -------------------------------------------
        // Improvement:
        // - Validation: The code reads directly from $request->all() without any validation or sanitation, making it prone to malformed input and potentially vulnerable.
        // - Data Integrity: Checking if the values are empty strings ("") instead of performing stricter type checks could lead to errors or improper records in the database.
        // - Error Handling: There's no exception handling for potential database query failures.
        // - Response Consistency: Returning a string "Please, add comment" in one execution path and a response object for another is inconsistent, which may cause confusing and mixed content types.
        // - Database Query Efficiency: Separate database update queries for Distance and Job could potentially be optimized, particularly if they concern the same job.
        // - Readability and Maintenance: Multiple if-else constructs make the code harder to read and maintain. Using Laravel's request validation and pulling only the needed fields could simplify this.
        // - Use of Magic Strings: Strings like 'yes' and 'no' are used to represent boolean concepts, and hard-coded strings could be replaced with class constants or configuration variables.
        // Define validation rules
        $validated = $request->validate([
            'jobid' => 'required|integer|exists:jobs,id',
            'distance' => 'nullable|numeric',
            'time' => 'nullable|numeric',
            'session_time' => 'nullable|numeric',
            'flagged' => 'required|boolean',
            'manually_handled' => 'required|boolean',
            'by_admin' => 'required|boolean',
            'admincomment' => 'required_if:flagged,true',
        ]);

        // Simplify the defaults using the null coalesce operator
        $distance = $validated['distance'] ?? '';
        $time = $validated['time'] ?? '';
        $session = $validated['session_time'] ?? '';
        $comment = $validated['admincomment'] ?? '';
        
        // Replace string 'yes'/'no' with boolean
        $flagged = $validated['flagged'];
        $manuallyHandled = $validated['manually_handled'];
        $byAdmin = $validated['by_admin'];

        // Gathering all updateable attributes
        $attributes = compact('admincomment', 'flagged', 'session_time', 'manually_handled', 'by_admin');

        // Perform the updates (conditional on whether the values actually exist)
        $jobUpdated = Job::where('id', $validated['jobid'])->update($attributes);

        // Assuming 'distance' and 'time' belong to a different model/table
        $distanceUpdated = false;
        if ($distance || $time) {
            $distanceUpdated = Distance::where('job_id', $validated['jobid'])->update(array_filter(compact('distance', 'time')));
        }

        if (!$jobUpdated && !$distanceUpdated) {
            return response()->json(['message' => 'No updates performed.'], 400);
        }

        return response()->json(['message' => 'Record updated!'], 200);
    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return response($response);
        // -------------------------------------------
        // Improvement:
        // - Lack of Input Validation: The method does not validate the request data, meaning it could potentially act on incomplete or incorrect data.
        // - Mass Assignment: Using $request->all() can lead to mass assignment vulnerabilities if not properly guarded within the repository method.
        // - Error Handling: No error handling is present, so if reopen fails within the repository, there is no clear contingency to manage the error and inform the user gracefully.
        // - Uninformative Response: The method returns $response, which could be any type of data, and there's no clear HTTP status code that provides contextual feedback about the operation's success.
        // - Ambiguous Behavior: There are no comments or documentation specifying what should be in $data, how the reopen function behaves, or what constitutes a successful reopening.

        // Example of validating only the needed data, such as a job ID.
        $validatedData = $request->validate([
            'job_id' => 'required|integer|exists:jobs,id', // Simplified for example purposes
            // Other necessary fields to validate
        ]);

        // Assuming you have proper exception handling within your repository:
        try {
            $response = $this->repository->reopen($validatedData);
            return response()->json($response, 200); // Assuming the repository method returns an array or object suitable for a JSON response.
        } catch (\Exception $e) {
            // Log the exception and provide an error message for the user.
            return response()->json(['message' => 'Failed to reopen the job. Please try again.'], 500);
        }
    }

    public function resendNotifications(Request $request)
    {
        // $data = $request->all();
        // $job = $this->repository->find($data['jobid']);
        // $job_data = $this->repository->jobToData($job);
        // $this->repository->sendNotificationTranslator($job, $job_data, '*');

        // return response(['success' => 'Push sent']);
        // -------------------------------------------
        // What's OK:
        //     - Single Responsibility: The method addresses a single action - resending notifications for a job, which is favorable for clarity and maintainability.

        // Improvement:
        //     - Lack of Validation: The code does not validate the jobid obtained from the request. It’s assumed to exist and be correct, which might not always be the case, thus potentially causing errors down the line.
        //     - Potential for NotFoundException: The find method may return null if there's no match for the jobid, but the subsequent call to jobToData does not handle this scenario. If job is null, an attempt to process it further could result in errors.
        //     - Error Handling: There's no explicit error handling or response differentiation based on the success or failure of the underlying methods.
        //     - Magic Value: The wildcard ' * ' being passed to sendNotificationTranslator could be considered a magic value since its purpose isn't clear from the context.
        //     - Hard-coded Success Message: The response indicates success regardless of the actual outcome of the notification send operation.
        //     - Response Format: A string array ['success' => 'Push sent'] is used to signal operation success, but HTTP status codes are more suitable for conveying the result's nature.
        $validatedData = $request->validate([
            'jobid' => 'required|integer|exists:jobs,id',
        ]);
    
        try {
            $job = $this->repository->find($validatedData['jobid']);
            $job_data = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $job_data);
    
            return response()->json(['success' => 'Notifications were resent successfully.'], 200);
        } catch (\Exception $e) {
            // Log the exception if needed 
            return response()->json(['error' => 'Failed to resend notifications.'], 500);
        }
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        // $data = $request->all();
        // $job = $this->repository->find($data['jobid']);
        // $job_data = $this->repository->jobToData($job);

        // try {
        //     $this->repository->sendSMSNotificationToTranslator($job);
        //     return response(['success' => 'SMS sent']);
        // } catch (\Exception $e) {
        //     return response(['success' => $e->getMessage()]);
        // }
        // -------------------------------------------
        // Improvement:
        // - Input Validation: Similar to before, $request->all() is used to retrieve the request data with no prior validation. This can be insecure as it might allow for improper or unexpected data to be processed.
        // - Error Response: While exceptions are caught, the response for both success and failure cases is ambiguous. The success response structure is used regardless of the outcome, which is misleading and not a recommended practice.
        // - Fail-Silently Approach: The code is structured to send the exception message directly to the client. This may unintentionally expose internal system details to the end-user or potential attackers and should be avoided.
        // - Lack of Job Existence Check: There is no check for whether $job is null after calling find. If the jobid does not exist, it will lead to a server-side error when trying to use the $job variable.
        // - Response Type: The PHPDoc indicates a complex return type that includes both a Laravel and Symfony response type. While this could just be due to Laravel's facade pattern, a cleaner indication of what is actually being returned (usually a JSON response) might be preferable.
        $validatedData = $request->validate([
            'jobid' => 'required|integer|exists:jobs,id',
        ]);
    
        try {
            $job = $this->repository->find($validatedData['jobid']);
            if (!$job) {
                throw new \RuntimeException("Job not found");
            }
    
            $this->repository->sendSMSNotificationToTranslator($job);
    
            return response()->json(['message' => 'SMS notification resent successfully.'], 200);
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            return response()->json(['error' => 'Failed to resend SMS notification.'], 500);
        }
    }

}

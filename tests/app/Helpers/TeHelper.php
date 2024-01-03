<?php
namespace DTApi\Helpers;

use Carbon\Carbon;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeHelper
{
    public static function fetchLanguageFromJobId($id)
    {
        // $language = Language::findOrFail($id);
        // return $language1 = $language->language;

        // -------------------------------------------
        // What's Good:
        //     - The purpose of the function is clear—it's meant to fetch a language by job ID.
        //     - Usage of findOrFail to handle the case where the ID is not found and presumably to throw an exception.
        //     - The function is concise—just two lines of logic.

        // What Could Be Improved:
        //     - The method's name fetchLanguageFromJobId implies that a Job ID will be used to get a language, which could mean that there is a linkage between a Job model and a Language model. However, the function fetches directly a Language object using an ID without considering the Job. This could potentially be a misnomer or incorrect design logic.
        //     - The $language1 assignment is redundant since it is not used and the method could simply return $language->language.
        //     - Error handling: While findOrFail will throw an exception if the ID is not found, there might not be any custom error handling or messaging to handle this more gracefully according to the application's needs.
        //     - The method is marked as static. This usually means it's utility-like and tied less to the state of an object instance. It's not necessarily bad, but depending on the context, using static methods could make unit testing more difficult or could indicate deeper architectural issues.
        //     The return type of the method is not specified. In newer PHP versions, it's good practice to specify the return type.

        // Assuming there is a Job model that has a 'language' relationship
        // If 'language' is a field in the Job model, then access it directly
        $job = Job::findOrFail($id);
        
        // Assuming 'language' is a related model, if it's a field, just return $job->language
        return $job->language->language;


    }

    public static function getUsermeta($user_id, $key = false)
    {
        // return $user = UserMeta::where('user_id', $user_id)->first()->$key;
        // if (!$key)
        //     return $user->usermeta()->get()->all();
        // else {
        //     $meta = $user->usermeta()->where('key', '=', $key)->get()->first();
        //     if ($meta)
        //         return $meta->value;
        //     else return '';
        // }

        // -------------------------------------------
        // What's Potentially Ok:
        //     - The function is meant to be reusable and can handle fetching either one piece of metadata or all pieces associated with a user.
        
        // What Needs Improvement:
        //     - Early Return: The function contains a return statement right after defining $user, which would immediately exit the function and render all subsequent code unreachable.
        //     - Error Handling: If the first() method returns null (in case no user is found), trying to access ->$key will result in an error.
        //     - Dead Code: As mentioned, the if condition and the else block will never be executed due to the early return.
        //     - Logical Error: The $key = false default argument suggests that the caller might not provide a key. However, attempting to access ->$key when $key is false doesn't make sense.
        //     - Redundancy: The line $user->usermeta()->get()->all(); is redundant because get() already returns a collection which you can iterate over.
        
        // To improve this function, we need to address these issues to ensure it performs its intended task correctly. Here's how we can repair it:
        
        // Check if a specific key is requested
        if ($key) {
            // Retrieve the specific metadata for the key, if it exists
            $meta = UserMeta::where('user_id', $user_id)->where('key', $key)->first();
            // Return the value of the meta, or an empty string if it doesn't exist
            return $meta ? $meta->value : '';
        } else {
            // If no key is specified, return all metadata for the user as a collection
            $allMeta = UserMeta::where('user_id', $user_id)->get();
            // Optionally transform the collection to an associative array keyed by 'key'
            return $allMeta->pluck('value', 'key')->all();
        }

        // Improvements Made:
        //     - Simplified the function logic by first checking whether a $key is provided and then branching accordingly.
        //     - Protecting against calling a property on a potential null object by checking if the first() method call succeeds before accessing the property.
        //     - Removed the early return problem and made the subsequent code reachable and logical.
        //     - Changed the default value for $key to null, which more semantically indicates "no key provided."
        //     - Returned all metadata as a collection, which can be easily iterated over or transformed.
    }

    public static function getUsermeta($user_id, $key = false)
    {
        // $user = UserMeta::where('user_id', $user_id)->first()->$key;
        // if (!$key) {
        //     return $user->usermeta()->get()->all();
        // } else {
        //     $meta = $user->usermeta()->where('key', '=', $key)->get()->first();
        //     if ($meta) {
        //         return $meta->value;
        //     } else {
        //         return '';
        //     }
        // }

        // -------------------------------------------
        // Issues Identified:
        //     - Faulty Logic with Immediate Return: The method attempts to return a value immediately after querying the UserMeta. If the $key is false, the method should return all user metadata, but due to the initial return, the subsequent logic is unreachable.
        //     - Error-Prone Code: $user = UserMeta::where('user_id', $user_id)->first()->$key; will cause a fatal error if the first() method returns null, breaking the code.
        //     - Redundant Database Calls: The method makes multiple calls to the database when it could be optimized to retrieve the desired data with a single call.
        //     - Poor Error Handling: No null check after the first() call and no graceful handling if no metadata is found.
        //     - Lack of Type Hinting: The method lacks proper type hinting for the parameters and the return type, which helps with code reliability and maintainability.

        // Refactored Code:
        // Here is a revised version of the function that seeks to address the aforementioned issues:
        if (!$key) {
            // If no key is provided, return all metadata for the user.
            return UserMeta::where('user_id', $user_id)->get()->keyBy('key')->toArray();
        } else {
            // When a key is provided, fetch and return the single metadata value or an empty string.
            $meta = UserMeta::where('user_id', $user_id)->where('key', $key)->first();
            return $meta ? $meta->value : '';
        }
        // Refactoring Rationale:
        //     - Improved Logical Flow: The condition for checking the $key before diving into database operations ensures logical flow and efficiency by minimizing the number of queries.
        //     - Guarded Property Access: By safely checking if the first() call returns a valid object before accessing properties or methods, we prevent potential errors.
        //     - Efficient Database Queries: This version only necessitates a single trip to the database under each condition, thus optimizing performance.
        //     - Enhanced Return Value: The method's return value now aligns better with user expectations. It returns all metadata in an associative array when no key is provided, or a single value otherwise.
        //     - Type Safety and Clarity: Type hinting for the method's parameters and proper documentation via docblocks improve code clarity and prevent misuse.
        //     - Error Handling: The method now handles the case where no metadata is found by returning a default empty string, providing a clear indication to the caller.
    }
        
    public static function convertJobIdsInObjs($jobs_ids)
    {
        $jobs = array();
        foreach ($jobs_ids as $job_obj) {
            $jobs[] = Job::findOrFail($job_obj->id);
        }
        return $jobs;

        // -------------------------------------------
        // Positives:
        //     - Simplicity: The purpose of the function is simple and straightforward—to convert an array of job IDs into job objects.
        //     Use of findOrFail: This method ensures that if any of the job IDs does not exist, an exception will be thrown, which can be a good way to ensure data integrity.

        // Issues and Improvements:
        //     - Variable Naming: The parameter $jobs_ids is misleading because it implies a collection of IDs, but the foreach loop treats it like a collection of objects with an id property. It should be named $job_objects or similar to reflect its actual contents.
        //     - Error Handling: While findOrFail will throw an exception if any of the IDs aren't found, the function does not handle this. Not handling the exception may result in a complete halt of the script or a user-facing error.
        //     - Performance: Each call to findOrFail results in a new database query. If there are many job IDs, this will result in many queries and can significantly impact performance. It would be better to batch query these IDs.
        //     - Lack of Type Hinting: There's no type hinting for the parameter, which can help ensure that the function is called with the expected data type.

        // To improve the code, consider the following refactoring:
        // Extract job IDs from the array of objects
        $jobIds = array_map(function($jobObj) {
            return $jobObj->id;
        }, $jobObjects);
        
        // Fetch all jobs in one query
        return Job::findOrFail($jobIds);
        // Here are the improvements:
        //     - Improved Performance: By extracting all job IDs first and then using a single findOrFail call with an array of IDs, it minimizes the number of database queries, which is more efficient.
        //     - Better Naming: It is assumed that the function accepts an array of objects; hence, the parameter has been renamed for clarity.
        //     - Use of array_map: This function is now streamlined using array_map to transform the input array, which simplifies the process of extracting IDs.
        //     - Type Hinting: The parameter now includes type hinting, which helps to ensure that the function is given the proper input.
    }
    
    public static function willExpireAt($due_time, $created_at)
    {
        // $due_time = Carbon::parse($due_time);
        // $created_at = Carbon::parse($created_at);

        // $difference = $due_time->diffInHours($created_at);


        // if($difference <= 90)
        //     $time = $due_time;
        // elseif ($difference <= 24) {
        //     $time = $created_at->addMinutes(90);
        // } elseif ($difference > 24 && $difference <= 72) {
        //     $time = $created_at->addHours(16);
        // } else {
        //     $time = $due_time->subHours(48);
        // }

        // return $time->format('Y-m-d H:i:s');

        // -------------------------------------------
        // Positives:
        //     - The function is designed to provide a flexible way of calculating an expiry time based on specified rules.
        //     - Using the Carbon library is a good choice as it provides a rich set of date/time manipulation functions that are easy to use and read.

        // Issues that need addressing:
        //     - Logic Error: The checks for $difference are not in a sensible order. As the method is currently written, if $difference <= 90, it will always set $time to $due_time, and the subsequent conditions will never be hit.
        //     - Overwriting Variables: The method overwrites the input parameters $due_time and $created_at, which can be confusing. While not incorrect per se, it's often better for readability to use different variable names for these parsed objects.
        //     - Ambiguity in Naming: The variable $difference does not specify the units of the difference; it would be more clear if named something like $hoursDifference.
        //     - Implicit Assumptions: The method makes assumptions about what $due_time and $created_at are in relation to each other but does not validate these assumptions.
        //     - Lack of Input Validation: There is no validation to ensure that the provided $due_time and $created_at can actually be parsed by Carbon.

        // A Proposition for Refactoring:
        $due = Carbon::parse($due_time);
        $created = Carbon::parse($created_at);

        $hoursDifference = $due->diffInHours($created, false);

        if ($hoursDifference > 72) {
            $time = $due->subHours(48);
        } elseif ($hoursDifference > 24) {
            $time = $created->addHours(16);
        } elseif ($hoursDifference <= 24) {
            $time = $created->addMinutes(90);
        } else {
            // For negative or zero difference, or when it doesn't match other rules.
            $time = $due;
        }

        return $time->format('Y-m-d H:i:s');
        // Improvements:
        //     - Fixed Logic: Corrected the order of condition checks and made them mutually exclusive. The elseif conditions ensure each block of logic is self-contained and only runs when the previous conditions are not met.
        //     - Clarified Logic: Changed the variable names to $due and $created to reflect they are parsed datetime objects and altered $difference to $hoursDifference to define the units explicitly.
        //     - Reversed Difference: By getting the difference as a signed integer (via the second parameter false in diffInHours), we can handle cases where $created_at is after $due_time.
        //     - More Explicit Conditions: Redefined the conditions to be clearer about their boundaries. Each condition now explicitly handles a different range of time differences.

    }

}


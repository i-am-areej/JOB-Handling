
This repository includes unit tests for critical methods in the TeHelper and UserRepository classes. These tests ensure that the functionality and business logic of the application remain consistent and reliable.


Tests Description:
1. TeHelperTest:

    1. Purpose: 
        Ensures the willExpireAt method correctly calculates expiration times based on various scenarios.

    2. Scenarios Tested:
        due_time within 90 minutes.
        due_time less than 24 hours.
        due_time between 24 and 72 hours.
        due_time greater than 72 hours.
        Edge case: due_time exactly 24 hours.


2. UserRepositoryTest:

    1. Purpose: 
        Verifies the createOrUpdate method for both creating and updating users.

    2. Scenarios Tested:
        Successfully creating a new user.
        Updating an existing user with new attributes.
        Handling cases where the password is missing during an update.
        Ensures role, metadata, and related data are properly managed.



---------------------------------------------------------------------- THANKYOU --------------------------------------------------------------------------------------------
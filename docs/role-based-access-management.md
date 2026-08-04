# Role-Based Access Management

## Overview

The Curriculum Mapping Tool uses two layers to determine what a user can access and do:

1. **Role** — a role type assigned to the user (Admin, Department Head, Program Director, or User) that grants broad access to resources (courses, programs, or syllabi) across the system.
2. **Direct collaboration permissions** — explicit access granted on individual courses, programs, or syllabi, regardless of role.

Both layers can apply to the same user at the same time. For example, a Program Director may also be added as a collaborator on a course outside their program, combining both sources of access.

## User Roles

### Admin

Administrators have full system-wide access. They can view and edit all courses and programs across the entire system. Administrators are also the only role that can assign or change roles for other users.

### Department Head

Department Heads can view and edit all courses and programs within their assigned department(s). With some setting during role assignment by admins, a Department Head may also be granted access to all courses across their faculty, beyond their specific department.

### Program Director

Program Directors can view and edit all courses and programs within their assigned program(s). With some setting during role assignment by admins, a Program Director may also be granted access to all courses across their faculty, beyond their specific program.

### User

The User role is the default assigned to any self-registered account. Users can view and edit only the courses and programs they created themselves, or those they have been explicitly added to as a collaborator.

## Collaboration Permissions

Regardless of role, individual courses, programs, and syllabi support direct collaborator access. When a user is added as a collaborator on a resource, they are assigned one of three permission levels:

| Permission | Access |
|------------|--------|
| **Owner** | Full control over the resource |
| **Editor** | Can view and modify content |
| **Viewer** | Read-only access |

When a role (Administrator, Department Head, or Program Director) grants a user access to a resource, that access is treated as **Owner-level**, the same as if they had been explicitly added as an owner collaborator.

## Role Assignment

- New accounts are automatically assigned the **User** role upon self-registration.
- Only **Administrators** can assign or change roles for other users.

## Faculty Course Code Maintenance

Faculty ownership of course codes is stored in `faculty_course_codes`. The initial Forestry mappings are added by `FacultyCourseCodeSeeder`, but running the seeder does not create, remove, or rebuild related `course_user_role` rows.

This matters om the rare case when a new course code is assigned to a faculty or an existing code changes ownership. Existing courses and faculty-wide users are not synchronized automatically, which can leave search and dashboard access incorrect.

Whenever possible, add the mapping before creating courses with that code so normal course creation can add the required access rows. For an existing database, use a data migration or careful SQL instead of only editing and rerunning the seeder.

The update should:

1. Add or remove the `faculty_course_codes` mapping.
2. Find existing courses with the affected code.
3. Add or remove the affected `course_user_role` rows while preserving access granted through another role or direct permission.
4. Verify the affected users' dashboard and search results.

Manual role rows must include the correct user, course, role, and related department or program IDs. A future improvement would be a synchronization service or Artisan command that performs these updates in a transaction.

## Role Assignment Interface (Admins Only Access)

![Role Assignment Interface](./images/RoleAssignmentInterface(AdminOnlyAccess).png)

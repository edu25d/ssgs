-- Role registry for External Google OAuth.
-- Google authenticates a person; this table grants the application role.

create table if not exists public.user_profiles (
  user_id uuid primary key references auth.users (id) on delete cascade,
  role text not null check (role in ('teacher', 'admin')),
  status text not null check (status in ('pending', 'approved', 'active', 'disabled')),
  display_name text not null default '',
  email text not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint user_profiles_role_status_check check (
    (role = 'teacher' and status in ('pending', 'approved', 'disabled')) or
    (role = 'admin' and status in ('active', 'disabled'))
  )
);

create index if not exists user_profiles_pending_teachers_idx
  on public.user_profiles (created_at)
  where role = 'teacher' and status = 'pending';

alter table public.user_profiles enable row level security;

revoke all on table public.user_profiles from anon, authenticated;
grant select, insert, update on table public.user_profiles to authenticated;

create or replace function public.is_active_admin()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.user_profiles as profile
    where profile.user_id = (select auth.uid())
      and profile.role = 'admin'
      and profile.status = 'active'
  );
$$;

revoke all on function public.is_active_admin() from public;
grant execute on function public.is_active_admin() to authenticated;

create or replace function public.is_approved_teacher()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.user_profiles as profile
    where profile.user_id = (select auth.uid())
      and profile.role = 'teacher'
      and profile.status = 'approved'
  );
$$;

revoke all on function public.is_approved_teacher() from public;
grant execute on function public.is_approved_teacher() to authenticated;

drop policy if exists "profiles_read_self_or_admin" on public.user_profiles;
create policy "profiles_read_self_or_admin"
  on public.user_profiles
  for select
  to authenticated
  using (user_id = (select auth.uid()) or (select public.is_active_admin()));

drop policy if exists "teacher_can_request_own_pending_profile" on public.user_profiles;
create policy "teacher_can_request_own_pending_profile"
  on public.user_profiles
  for insert
  to authenticated
  with check (
    user_id = (select auth.uid())
    and role = 'teacher'
    and status = 'pending'
    and lower(email) = lower((select auth.jwt() ->> 'email'))
  );

drop policy if exists "admins_update_profiles" on public.user_profiles;
create policy "admins_update_profiles"
  on public.user_profiles
  for update
  to authenticated
  using ((select public.is_active_admin()))
  with check ((select public.is_active_admin()));

-- Bootstrap the first admin only after that person has completed one Google sign-in.
-- Replace the example email, review it, and run manually in the Supabase SQL editor.
-- Do not expose this privilege through a public signup form.
--
-- insert into public.user_profiles (user_id, role, status, display_name, email)
-- select
--   auth_user.id,
--   'admin',
--   'active',
--   coalesce(auth_user.raw_user_meta_data ->> 'full_name', auth_user.raw_user_meta_data ->> 'name', auth_user.email),
--   auth_user.email
-- from auth.users as auth_user
-- where lower(auth_user.email) = lower('REPLACE_WITH_APPROVED_ADMIN_GOOGLE_EMAIL')
-- on conflict (user_id) do update
-- set role = 'admin', status = 'active', updated_at = now();

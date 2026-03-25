alter table if exists users
    add column if not exists birth_date date,
    add column if not exists is_age_verified boolean default false;

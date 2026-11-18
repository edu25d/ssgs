# Google OAuth External 설정 · ver4

이 안내는 여러 학교의 Google Workspace 계정과 개인 Google 계정을 허용하는 설정입니다. Google 계정으로 로그인했다고 교사·관리자 권한이 자동으로 생기지는 않습니다.

## 접근 원칙

- Google OAuth 앱 대상은 `External`로 설정합니다. 이를 통해 한 학교 도메인에만 제한하지 않고 여러 Workspace와 개인 Google 계정을 인증할 수 있습니다.
- 앱이 처음 `Testing` 상태일 때는 Google Cloud에서 등록한 테스트 사용자만 로그인할 수 있습니다. 실제 파일럿 사용자 전체를 받을 시점에는 앱 게시 상태와 Google의 검증 요구사항을 확인합니다.
- Google 로그인은 계정 신원 확인용입니다. 교사 역할은 승인 상태가 `approved`인 `user_profiles` 행이 있어야 허용합니다.
- 관리자 역할은 `user_profiles`에 사전 등록되어 상태가 `active`인 계정만 허용합니다. 공개 가입으로 관리자 역할을 만들 수 없습니다.
- 로그인 범위는 `openid`, `email`, `profile`만 사용합니다. Google Drive, Gmail 등 별도 Google API 접근 권한은 요청하지 않습니다.
- Google Client Secret은 Google Cloud에서 발급받아 Supabase 대시보드의 Google Provider 설정에만 저장합니다. 브라우저 코드, `runtime-config.js`, 저장소, 학생에게 노출하지 않습니다.

## Google Cloud 설정

1. Google Cloud Console에서 프로젝트를 선택하거나 새 프로젝트를 만듭니다.
2. Google Auth Platform에서 Branding과 Audience를 설정하고 User type을 `External`로 선택합니다.
3. 로그인 범위는 기본 `openid`, 이메일, 프로필만 요청합니다. 이 서비스는 Drive·Sheets·Docs API 권한을 요청하지 않습니다.
4. OAuth Client ID를 만들고 application type을 `Web application`으로 선택합니다.
5. Authorized JavaScript origins에 아래 주소를 등록합니다.

```text
https://l75cibjp1nxa0w6u.jitda.io
http://localhost:3000
```

6. Supabase Dashboard의 Authentication → Providers → Google에서 안내하는 Callback URL을 Authorized redirect URIs에 그대로 등록합니다. 주소 형식은 보통 아래와 같지만, 실제 프로젝트 대시보드에 표시된 값을 사용해야 합니다.

```text
https://<SUPABASE_PROJECT_REF>.supabase.co/auth/v1/callback
```

7. 발급된 OAuth Client ID와 Client Secret을 Supabase Dashboard의 Authentication → Providers → Google에 입력하고 Google Provider를 켭니다. Secret은 이 웹 앱에 입력하지 않습니다.
8. Google Consent Screen이 `Testing`이면 모든 체험 계정을 Test users에 등록합니다. 실제 여러 학교가 사용할 때는 Production 게시 및 필요한 Google 검증을 진행합니다.

## Supabase 설정

1. Supabase Dashboard에서 Authentication → URL Configuration을 엽니다.
2. Site URL을 실제 서비스 주소로 설정합니다.
3. Redirect URLs에 현재 미리보기와 로컬 개발 주소를 허용합니다.

```text
https://l75cibjp1nxa0w6u.jitda.io/**
http://localhost:3000/**
```

4. Authentication → Providers → Google에서 Provider를 활성화하고 Google Cloud의 Client ID와 Client Secret을 저장합니다.
5. `supabase_auth_roles_ver4.sql`을 SQL Editor에서 실행합니다.
6. 첫 Super Admin은 Google OAuth로 한 번 로그인합니다. 권한이 없는 상태이므로 앱은 관리자 화면을 열지 않습니다. `user_profiles`에 해당 Auth 사용자 ID를 `role='admin'`, `status='active'`로 등록하는 주석 처리된 bootstrap SQL을 검토·수정한 뒤 SQL Editor에서 실행합니다.
7. 이후 교사 계정은 Google OAuth 로그인 때 `role='teacher'`, `status='pending'`으로 신청됩니다. 활성 관리자가 승인해야 교사 평가 화면이 열립니다.

## 앱 설정값

현재 서버는 런타임 환경변수에서 아래 두 값만 읽어 `/runtime-config.js`로 제공합니다.

- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`

Publishable Key는 브라우저에서 사용하도록 설계된 공개 키입니다. `service_role` 또는 Secret Key로 대체하지 마세요. 서버 전용 키는 브라우저에 전달되지 않도록 해야 합니다.

현재 작업 환경에서는 Publishable Key 입력이 생략되어 실제 OAuth 버튼은 비활성화되고, 명시적인 데모 버튼만 동작합니다. 키를 환경에 추가한 뒤 개발 서버를 다시 시작하고, Supabase Google Provider 설정까지 마쳐야 실제 로그인을 시험할 수 있습니다.

`supabase_auth_roles_ver4.sql`은 역할 레지스트리의 RLS만 설정합니다. 실제 평가 답안·점수·챗봇 대화 테이블을 만들 때는 각 테이블에 학생 본인, 승인된 담당 교사, 관리자 메타데이터 범위를 제한하는 RLS 정책과 허용·거부 테스트를 별도로 추가해야 합니다.

## 로그인 후 역할 처리

- Supabase Auth는 Google 계정의 신원을 인증합니다.
- 브라우저는 자기 `user_profiles` 행만 읽을 수 있습니다. 서비스는 프로필의 역할과 상태를 확인한 후 적절한 화면을 엽니다.
- 새 Google 계정으로 교사를 선택하면 승인 대기 요청만 생성됩니다.
- 새 Google 계정으로 관리자를 선택하면 거부합니다. Super Admin이 사전에 등록해야 합니다.
- Google 계정 이메일 도메인에 따라 권한을 자동 부여하지 않습니다. 개인 Gmail도 교사 권한은 관리자 승인 전까지 대기 상태입니다.
- 이 데모의 학생 접속은 학급 코드·반·번호·이름·4자리 PIN 조합으로 시연됩니다. 실제 운영에서는 PIN 확인 후 안전한 세션을 만들고 서버 측 권한을 검증해야 합니다.

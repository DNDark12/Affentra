<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lời mời tham gia Affentra</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Xin chào {{ $user->name }},</h2>
        
        <p>Bạn vừa được mời tham gia hệ thống quản lý Affiliate - <strong>{{ config('app.name') }}</strong> với vai trò Cộng tác viên (Partner).</p>
        
        @if ($tempPassword)
            <p>Thông tin đăng nhập tạm thời của bạn như sau:</p>
            <ul>
                <li><strong>Email:</strong> {{ $user->email }}</li>
                <li><strong>Mật khẩu:</strong> <code>{{ $tempPassword }}</code></li>
            </ul>
        @else
            <p>Hệ thống nhận thấy bạn đã có sẵn tài khoản. Vui lòng đăng nhập bằng mật khẩu của bạn hoặc qua Google.</p>
        @endif

        <p>Để hoàn tất việc tạo tài khoản và kích hoạt trạng thái, vui lòng nhấn vào nút xác nhận bên dưới:</p>
        
        <div style="margin: 30px 0;">
            <a href="{{ $signedUrl }}" style="background-color: #6366F1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">
                Xác Nhận Tài Khoản
            </a>
        </div>

        <p><em>Lưu ý: Liên kết này sẽ hết hạn sau thời gian ngắn. Bạn nên thay đổi mật khẩu ngay sau khi đăng nhập lần đầu tiên.</em></p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
        <p style="font-size: 12px; color: #666;">Nếu bạn không có ý định tham gia, vui lòng bỏ qua email này.</p>
    </div>
</body>
</html>

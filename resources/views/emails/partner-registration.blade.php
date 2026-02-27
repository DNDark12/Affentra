<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lời mời đăng ký tài khoản Affentra</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Xin chào,</h2>
        
        <p>Bạn vừa nhận được lời mời tham gia hệ thống quản lý Affiliate - <strong>{{ config('app.name') }}</strong> với vai trò Cộng tác viên (Partner) từ quản lý <strong>{{ $manager->name }}</strong>.</p>
        
        <p>Hiện tại trên hệ thống chưa có tài khoản nào được liên kết với email này. Vui lòng nhấn vào nút bên dưới để tiến hành đăng ký và kích hoạt tài khoản của bạn:</p>
        
        <div style="margin: 30px 0;">
            <a href="{{ $registrationUrl }}" style="background-color: #6366F1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">
                Đăng Ký Tài Khoản
            </a>
        </div>

        <p>Sau khi đăng ký thành công, bạn sẽ tự động được gán vào mạng lưới của quản lý {{ $manager->name }}.</p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
        <p style="font-size: 12px; color: #666;">Nếu bạn không có ý định tham gia, vui lòng bỏ qua email này.</p>
    </div>
</body>
</html>

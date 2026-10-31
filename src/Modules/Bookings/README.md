# Bookings

Module Bookings sở hữu dữ liệu preview và booking. Ở giai đoạn 8, module mới sử
dụng bảng `XemTruocDatCho`; các aggregate booking, payment và hủy sẽ được bổ sung
ở các giai đoạn sau.

## Luồng preview

1. `POST /previews` chỉ nhận request của tài khoản `CUSTOMER` đã đăng nhập và có
   CSRF hợp lệ.
2. Client gửi criteria, property slug, timing, selection fingerprint và ánh xạ
   room/product; không gửi tổng tiền hoặc policy đáng tin cậy.
3. `PreviewService` gọi `PropertyAvailabilityService` để đọc lại property,
   organization, product, policy, rate, restriction, promotion, capacity và tồn.
4. Nếu mapping thay đổi, request bị từ chối. Nếu mapping còn nguyên nhưng
   giá/version đổi, preview mới được tạo với warning yêu cầu khách xác nhận.
5. `PreviewSnapshotFactory` tạo `PhanBoJSON` version 1 và fingerprint canonical.
6. Token ngẫu nhiên 256 bit được trả đúng một lần; database chỉ lưu SHA-256.
7. Preview ở trạng thái `VALID` trong tối đa 15 phút và không tăng counter tồn,
   không tạo `GiuTonPhongDem`.

## Checkout

- Web giữ token thô trong PHP session rồi redirect tới `GET /checkout`.
- API gửi token trong header `X-Preview-Token`.
- Token không nằm trong path/query string, HTML hoặc application log.
- Checkout revalidate snapshot một lần nữa. Preview hết hạn, đã dùng,
  invalidated, sai owner hoặc stale đều không dùng được; stale preview được đổi
  sang `INVALIDATED`.
- Response checkout đặt `Referrer-Policy: no-referrer`, `Cache-Control: no-store`
  và CSP chỉ cho tài nguyên cùng origin.

## Ranh giới giai đoạn 8

Checkout hiện chỉ là màn hình xác nhận. Nút tạo booking bị vô hiệu hóa có chủ ý:
giai đoạn 9 mới khóa preview/tồn trong transaction, tạo booking/item/nightly
snapshot/ledger/payment và đánh dấu preview `USED`.

# Bookings

Module Bookings sở hữu preview, booking, snapshot lịch sử, payment, quản lý
booking, hủy/refund/waiver và đổi ngày lưu trú.

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

## Booking trả tại cơ sở

1. `POST /bookings` nhận preview token từ PHP session hoặc header
   `X-Preview-Token`, `ClientRequestID` UUID, liên hệ người đặt và xác nhận chính
   sách; không nhận giá hoặc tồn từ client.
2. `BookingService` khóa preview theo token hash, xử lý idempotency toàn cục và
   chỉ hỗ trợ preview thuần `PAY_AT_PROPERTY`.
3. Giá, product, policy, restriction, capacity và inventory được đọc lại. Sau
   đó inventory được khóa theo `(LoaiPhongID, NgayLuuTru)` để quyết định unit
   cuối.
4. Một transaction tạo `DatCho.CONFIRMED`, mọi `HangMucDatCho`, mọi
   `GiaDemDatCho`, ledger `GiuTonPhongDem.ACTIVE`, payment
   `PAY_AT_PROPERTY + NOT_TRACKED`, chuyển preview sang `USED` và ghi audit.
5. Bất kỳ lỗi nào rollback toàn bộ. Mã booking chỉ được trả sau commit.
6. Retry cùng UUID, owner và payload trả booking cũ. Cùng UUID nhưng khác owner,
   preview hoặc payload trả `409 IDEMPOTENCY_CONFLICT` không kèm dữ liệu booking
   đã tồn tại.

## Snapshot và xác nhận

- `GET /bookings/{code}/confirmation` luôn kiểm tra booking thuộc customer hiện
  tại; mã công khai không thay thế authorization.
- Trang xác nhận đọc tên phòng, sản phẩm, nhóm khách, giá đêm và policy từ các
  snapshot booking, không đọc lại catalog hiện tại.
- Response checkout/xác nhận dùng `no-store`, `no-referrer` và CSP cùng origin.
- `PAY_AT_PROPERTY` không ghi nhận tiền cơ sở trực tiếp thu: `DaThanhToan = 0`,
  `DaHoan = 0`, payment giữ trạng thái `NOT_TRACKED`.

## Đổi ngày lưu trú

- Chỉ booking `CONFIRMED + PAY_AT_PROPERTY + NOT_TRACKED` và còn trước giờ nhận
  phòng địa phương được đổi ngày.
- Preview luôn tính lại rate, restriction, promotion, chính sách và capacity;
  không giữ tồn và không tin tổng tiền từ client.
- Xác nhận khóa booking → payment → commercial rows → toàn bộ ledger → union
  inventory đã sort, rồi kiểm fingerprint và phần delta capacity.
- Đêm giao nhau giữ nguyên; old-only được release; new-only được insert hoặc tái
  kích hoạt ledger `RELEASED` để không vi phạm unique key.
- Ngày booking, nightly prices, snapshot item, tổng booking/payment, ledger,
  counter và audit thay đổi trong cùng transaction. Retry cùng `RequestID` và
  payload chỉ trả kết quả cũ.

## Ranh giới hiện tại

Module chưa xử lý no-show, completion, verified review và báo cáo quản trị; đó
là các giai đoạn sau. Mọi use case mới vẫn phải giữ snapshot lịch sử, tenant
scope, idempotency và lock order đã thiết lập.

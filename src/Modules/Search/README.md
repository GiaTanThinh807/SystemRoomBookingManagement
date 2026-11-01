# Search

Module Search không sở hữu bảng dữ liệu. Module chỉ đọc catalog của Properties,
Commercial và Inventory để tìm cơ sở, tính giá đại diện, phân bổ nhóm khách vào
phòng và kiểm tra lại lựa chọn trên trang chi tiết.

## Luồng chính

1. `SearchCriteriaFactory` chuẩn hóa query string, ngày lưu trú, tối đa 5 phòng,
   10 người lớn, 10 trẻ em và 30 đêm; filter/sort chỉ nhận giá trị allow-list.
2. `SearchCatalogRepository` lấy cơ sở `ACTIVE` tại HCM thuộc tổ chức
   `HOAT_DONG`, sản phẩm đang mở bán và đủ giá/tồn cho mọi đêm.
3. `PropertyAllocationService` dùng `DeterministicRoomAllocator` để thử tối đa
   hai nhánh thời điểm thanh toán. Tồn phòng được chia sẻ giữa các nhóm khách;
   một nhóm không bị tách qua nhiều phòng.
4. `SearchService` dùng `StandardPriceCalculator` để tạo đúng tổng giá hiển thị,
   sau đó mới lọc khoảng giá, sắp xếp và phân trang.
5. `PropertyAvailabilityService` đọc lại dữ liệu hiện hành khi mở detail hoặc đổi
   thời điểm thanh toán. Fingerprint cũ không còn khớp sẽ trả
   `selectionChanged=true` kèm lý do.

## HTTP công khai

- `GET /search`: trang kết quả hoặc JSON khi gửi `Accept: application/json`.
- `GET /properties/{slug}`: trang chi tiết và allocation hiện hành.
- `GET /properties/{slug}/availability`: JSON để đổi timing mà không dùng lại
  response cũ ở trình duyệt.

Các route trên cho phép khách chưa đăng nhập. Nút đặt phòng chưa hoạt động trong
giai đoạn này; preview và checkout thuộc giai đoạn 8.

## Invariant kỹ thuật

- Search là luồng chỉ đọc và không dùng `FOR UPDATE`.
- Allocation có thứ tự ổn định: tổng giá, ưu tiên `PAY_AT_PROPERTY`, rồi tuple
  room/product; tối đa 5 nhóm để giới hạn backtracking.
- Search và detail dùng chung calculator, allocator và fingerprint.
- Cache, nếu bổ sung sau này, chỉ được gợi ý ứng viên; availability cuối luôn đọc
  dữ liệu hiện hành.
- Kế hoạch truy vấn search, filter tiện nghi và detail được khóa bằng
  `SearchQueryPlanTest` trên fixture chuẩn.
# Cmnt
Thêm cmt

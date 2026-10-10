import http from './request'

export function uploadImage(file, scene = 'other') {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('scene', scene)
  return http.post('/upload/image', fd, { timeout: 120000 })
}

// 发票文件：PDF 或图片
export function uploadInvoiceFile(file) {
  const fd = new FormData()
  fd.append('file', file)
  return http.post('/upload/invoice', fd, { timeout: 120000 })
}
